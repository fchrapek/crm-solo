<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Messaging\Sender\CheckedHost;
use App\Services\Messaging\Sender\SenderConfig;
use App\Services\Messaging\Sender\SmtpSenderTransportFactory;
use App\Services\Messaging\Sender\TlsOnlyEsmtpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Throwable;

/**
 * Against a scripted SMTP server, the sender sends a login, envelope or
 * message only on a connection whose TLS handshake has completed, and keeps
 * sending on such a connection.
 */
final class SenderTlsProtocolTest extends TestCase
{
    private static string $certificate = '';

    /** @var resource|null */
    private $process;

    private string $log = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$certificate !== '' && is_file(self::$certificate)) {
            unlink(self::$certificate);
        }
        self::$certificate = '';

        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }

        parent::tearDown();
    }

    /** @return array<string, array{string, int}> */
    public static function plaintextServers(): array
    {
        return [
            'EHLO refused then HELO accepted, port 587' => ['helo-fallback', 587],
            'EHLO refused then HELO accepted, port 2525' => ['helo-fallback', 2525],
            'EHLO without STARTTLS, port 587' => ['plain', 587],
            'EHLO without STARTTLS, port 2525' => ['plain', 2525],
            'STARTTLS refused, port 587' => ['starttls-refused', 587],
            'STARTTLS accepted but no handshake, port 2525' => ['starttls-broken', 2525],
        ];
    }

    /** @return array<string, array{string, int}> */
    public static function tlsServers(): array
    {
        return [
            'STARTTLS on 587' => ['tls', 587],
            'STARTTLS on 2525' => ['tls', 2525],
            'implicit TLS on 465' => ['implicit', 465],
        ];
    }

    #[DataProvider('plaintextServers')]
    public function test_a_server_that_never_completes_tls_gets_no_login_and_no_envelope(string $script, int $port): void
    {
        $transport = $this->transport($port);

        $this->assertNotDelivered($transport, $script);
        $this->assertEnvelopeNeverSent(1);
    }

    public function test_the_tls_guard_alone_refuses_a_server_without_starttls(): void
    {
        $this->assertNotDelivered($this->bareGuard(), 'plain');
        $this->assertEnvelopeNeverSent(1);
    }

    public function test_after_starttls_is_refused_no_envelope_command_goes_out(): void
    {
        $transport = $this->bareGuard();
        $this->openPlain($transport, 'starttls-refused');

        $this->assertFails(fn () => $transport->executeCommand("STARTTLS\r\n", [220]));
        $this->assertFails(fn () => $transport->executeCommand("MAIL FROM:<hi@example.test>\r\n", [250]));
        $this->assertFails(fn () => $transport->executeCommand("AUTH PLAIN dGVzdA==\r\n", [235]));
        $transport->stop();

        $this->assertEnvelopeNeverSent(1);
    }

    public function test_a_220_to_starttls_without_a_completed_handshake_is_still_plain_text(): void
    {
        $transport = $this->bareGuard();
        $this->openPlain($transport, 'starttls-broken');

        $transport->executeCommand("STARTTLS\r\n", [220]);
        $this->assertFails(fn () => $this->socket($transport)->startTLS());
        $this->assertFails(fn () => $transport->executeCommand("MAIL FROM:<hi@example.test>\r\n", [250]));
        $this->assertFails(fn () => $transport->executeCommand("RCPT TO:<lead@example.test>\r\n", [250]));
        $this->assertFails(fn () => $transport->executeCommand("DATA\r\n", [354]));

        $this->assertEnvelopeNeverSent(1);
    }

    public function test_rset_and_noop_pass_in_plain_text_and_the_envelope_still_does_not(): void
    {
        $transport = $this->bareGuard();
        $this->openPlain($transport, 'plain');

        $transport->executeCommand("RSET\r\n", [250]);
        $transport->executeCommand("NOOP\r\n", [250]);
        $this->assertFails(fn () => $transport->executeCommand("MAIL FROM:<hi@example.test>\r\n", [250]));
        $transport->stop();

        $this->assertContains('1|RSET', $this->received());
        $this->assertContains('1|NOOP', $this->received());
        $this->assertEnvelopeNeverSent(1);
    }

    #[DataProvider('tlsServers')]
    public function test_a_completed_handshake_delivers_with_login(string $script, int $port): void
    {
        $transport = $this->transport($port);
        $this->connect($transport, $script);

        $transport->send($this->email());
        $transport->stop();

        $received = $this->received();
        $this->assertContains('1|MAIL FROM:<hi@example.test>', $received);
        $this->assertTrue($this->hasLineStarting($received, '1|AUTH'), 'the login went out over TLS');
    }

    #[DataProvider('tlsServers')]
    public function test_one_transport_keeps_sending_on_its_encrypted_connection_with_a_ping_between(string $script, int $port): void
    {
        $transport = $this->transport($port);
        $transport->setPingThreshold(0);
        $this->connect($transport, $script);

        $transport->send($this->email());
        $transport->start();
        $transport->send($this->email());
        $transport->stop();

        $received = $this->received();
        $this->assertCount(2, array_keys($received, '1|MAIL FROM:<hi@example.test>', true));
        $this->assertContains('1|NOOP', $received);
    }

    public function test_a_reconnect_to_a_server_that_drops_tls_sends_no_envelope_on_the_new_connection(): void
    {
        $transport = $this->bareGuard();
        $this->trust($transport);
        $this->connect($transport, 'tls,plain');

        $transport->send($this->email());
        $transport->stop();
        $this->assertNotDelivered($transport, null);

        $this->assertContains('1|MAIL FROM:<hi@example.test>', $this->received());
        $this->assertEnvelopeNeverSent(2);
    }

    /** A throwaway self-signed certificate for smtp.example.test, made per run so no key is committed. */
    private static function certificate(): string
    {
        if (self::$certificate === '') {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $request = openssl_csr_new(['commonName' => 'smtp.example.test'], $key, ['digest_alg' => 'sha256']);
            $certificate = openssl_csr_sign($request, null, $key, 2, ['digest_alg' => 'sha256']);
            openssl_x509_export($certificate, $pem);
            openssl_pkey_export($key, $keyPem);

            self::$certificate = (string) tempnam(sys_get_temp_dir(), 'smtp-cert-');
            file_put_contents(self::$certificate, $pem.$keyPem);
        }

        return self::$certificate;
    }

    private function transport(int $port): EsmtpTransport
    {
        $transport = (new SmtpSenderTransportFactory)->make(
            new SenderConfig('smtp.example.test', $port, 'box@example.test', 'pass-word', 'hi@example.test', null),
            new CheckedHost('smtp.example.test', '127.0.0.1', true),
        );
        $this->assertInstanceOf(TlsOnlyEsmtpTransport::class, $transport);
        $this->trust($transport);

        return $transport;
    }

    /** The guard without Symfony's own TLS requirement, which would refuse first. */
    private function bareGuard(): TlsOnlyEsmtpTransport
    {
        $transport = new TlsOnlyEsmtpTransport('127.0.0.1', 587, false);
        $transport->setRequireTls(false);
        $transport->setUsername('box@example.test');
        $transport->setPassword('pass-word');
        (new SmtpSenderTransportFactory)->secure($transport, new CheckedHost('smtp.example.test', '127.0.0.1', true));

        return $transport;
    }

    private function trust(EsmtpTransport $transport): void
    {
        $stream = $this->socket($transport);
        $options = $stream->getStreamOptions();
        $options['ssl']['cafile'] = self::certificate();
        $stream->setStreamOptions($options);
    }

    private function connect(EsmtpTransport $transport, string $scripts): void
    {
        $this->socket($transport)->setPort($this->serve($scripts));
    }

    /** A started connection that has tried neither STARTTLS nor a login, for probing commands one at a time. */
    private function openPlain(EsmtpTransport $transport, string $scripts): void
    {
        $this->connect($transport, $scripts);
        $transport->setAutoTls(false);
        $transport->setUsername('');
        $transport->start();
    }

    private function assertNotDelivered(EsmtpTransport $transport, ?string $scripts): void
    {
        if ($scripts !== null) {
            $this->connect($transport, $scripts);
        }

        try {
            $transport->send($this->email());
            $this->fail('the message was sent without a completed TLS handshake');
        } catch (TransportExceptionInterface) {
        } finally {
            $transport->stop();
        }
    }

    private function assertEnvelopeNeverSent(int $connection): void
    {
        $received = $this->received();
        $this->assertTrue($this->hasLineStarting($received, "{$connection}|EHLO") || $this->hasLineStarting($received, "{$connection}|HELO"), 'the scripted server was reached');
        foreach ($received as $line) {
            if (str_starts_with($line, "{$connection}|")) {
                $this->assertDoesNotMatchRegularExpression('/(AUTH|MAIL FROM|RCPT TO|DATA)/', $line, "an envelope command went out in plain text on connection {$connection}");
            }
        }
    }

    private function assertFails(callable $call): void
    {
        try {
            $result = $call();
        } catch (Throwable) {
            return;
        }
        $this->assertFalse($result, 'the call went through');
    }

    /** @return list<string> */
    private function received(): array
    {
        return array_map(fn (string $line): string => mb_rtrim($line, "\r\n"), file($this->log) ?: []);
    }

    /** @param  list<string>  $lines */
    private function hasLineStarting(array $lines, string $prefix): bool
    {
        return array_filter($lines, fn (string $line): bool => str_starts_with($line, $prefix)) !== [];
    }

    private function socket(EsmtpTransport $transport): SocketStream
    {
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);

        return $stream;
    }

    private function email(): Email
    {
        return (new Email)->from('hi@example.test')->to('lead@example.test')->subject('Hi')->text('Body');
    }

    private function serve(string $scripts): int
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'smtp-script-');
        $this->process = proc_open(
            [PHP_BINARY, base_path('tests/Fixtures/scripted-smtp-server.php'), $scripts, $this->log, self::certificate()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($this->process);

        $port = (int) fgets($pipes[1]);
        if ($port === 0) {
            $this->fail('the scripted server did not start: '.stream_get_contents($pipes[2]));
        }

        return $port;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SenderUnavailable;
use App\Models\Account;
use App\Models\Integration;
use App\Services\Messaging\Sender\AccountMailer;
use App\Services\Messaging\Sender\CheckedHost;
use App\Services\Messaging\Sender\HostResolver;
use App\Services\Messaging\Sender\SenderConfig;
use App\Services\Messaging\Sender\SenderTransportFactory;
use App\Services\Messaging\Sender\SmtpHostGuard;
use App\Services\Messaging\Sender\SmtpSenderTransportFactory;
use App\Services\Messaging\Sender\TlsOnlyEsmtpTransport;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Symfony\Component\Mailer\Transport\Smtp\Stream\ProcessStream;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * An account's own sender connects only to a public server it checked moments
 * before, over verified TLS, and its mailer keeps the instance's redirect and
 * demo refusal although it is built outside the mail manager.
 */
final class AccountSenderTest extends TestCase
{
    use RefreshDatabase;

    private const string PUBLIC_IP = '104.16.0.1';

    /** @var array<string, list<string>> */
    private array $dns = [];

    private ArrayTransport $transport;

    /** @var list<CheckedHost> */
    private array $connections = [];

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
        config(['app.demo' => false, 'mail.to.address' => null, 'mail.to.name' => null, 'mail.account_sender.blocked_subnets' => []]);

        $this->app->instance(HostResolver::class, new class($this->dns) implements HostResolver
        {
            /** @param  array<string, list<string>>  $dns */
            public function __construct(private array &$dns) {}

            public function addresses(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });

        $this->transport = new ArrayTransport;
        $this->app->instance(SenderTransportFactory::class, new class($this->transport, $this->connections) implements SenderTransportFactory
        {
            /** @param  list<CheckedHost>  $connections */
            public function __construct(private TransportInterface $transport, private array &$connections) {}

            public function make(SenderConfig $sender, CheckedHost $host): TransportInterface
            {
                $this->connections[] = $host;

                return $this->transport;
            }
        });

        $this->account = Account::factory()->create();
        $this->dns['smtp.example.test'] = [self::PUBLIC_IP];
    }

    /** @return array<string, array{string}> */
    public static function nonPublicAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10/8' => ['10.0.0.5'],
            'private 172.16/12' => ['172.20.1.1'],
            'private 192.168/16' => ['192.168.1.10'],
            'link-local' => ['169.254.169.254'],
            'CGNAT' => ['100.64.0.1'],
            'unspecified' => ['0.0.0.0'],
            'multicast' => ['224.0.0.1'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unique-local' => ['fd00::1'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv4-mapped private' => ['::ffff:10.0.0.1'],
            'NAT64 local use' => ['64:ff9b:1::a00:1'],
            'IPv6 site-local' => ['fec0::1'],
        ];
    }

    /** @return array<string, array{int, bool}> */
    public static function ports(): array
    {
        return ['465 implicit TLS' => [465, true], '587 STARTTLS' => [587, false], '2525 STARTTLS' => [2525, false]];
    }

    public function test_a_host_whose_every_answer_is_public_is_reached_at_a_checked_address(): void
    {
        $this->dns['smtp.example.test'] = ['2606:4700::1111', self::PUBLIC_IP];

        $checked = app(SmtpHostGuard::class)->check('smtp.example.test');

        $this->assertSame(['smtp.example.test', self::PUBLIC_IP, true], [$checked->host, $checked->address, $checked->public]);
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_a_host_with_any_answer_that_is_not_public_is_refused(string $address): void
    {
        $this->dns['smtp.example.test'] = [self::PUBLIC_IP, $address];

        $this->expectException(SenderUnavailable::class);
        $this->expectExceptionMessage('not public');

        app(SmtpHostGuard::class)->check('smtp.example.test');
    }

    public function test_the_stack_subnet_is_refused_even_when_its_range_is_public(): void
    {
        config(['mail.account_sender.blocked_subnets' => ['104.16.0.0/24']]);

        $this->expectException(SenderUnavailable::class);

        app(SmtpHostGuard::class)->check('smtp.example.test');
    }

    public function test_a_host_that_does_not_resolve_is_refused(): void
    {
        $this->expectException(SenderUnavailable::class);
        $this->expectExceptionMessage('does not resolve');

        app(SmtpHostGuard::class)->check('nowhere.example.test');
    }

    public function test_local_development_may_send_to_a_relay_on_a_private_address(): void
    {
        $this->app['env'] = 'local';
        $this->dns['mailpit'] = ['127.0.0.1'];

        $checked = app(SmtpHostGuard::class)->check('mailpit');

        $this->assertSame(['127.0.0.1', false], [$checked->address, $checked->public]);
    }

    #[DataProvider('ports')]
    public function test_the_transport_connects_to_the_checked_address_and_verifies_the_host_name_over_required_tls(int $port, bool $implicit): void
    {
        $transport = (new SmtpSenderTransportFactory)->make($this->config(port: $port), new CheckedHost('smtp.example.test', self::PUBLIC_IP, true));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame([self::PUBLIC_IP, $port, $implicit], [$stream->getHost(), $stream->getPort(), $stream->isTLS()]);
        $this->assertTrue($transport->isTlsRequired());
        $this->assertSame(10.0, $stream->getTimeout());
        $ssl = $stream->getStreamOptions()['ssl'];
        $this->assertSame(['smtp.example.test', true, true, false], [$ssl['peer_name'], $ssl['verify_peer'], $ssl['verify_peer_name'], $ssl['allow_self_signed']]);
        $this->assertSame(['box@example.test', 'pass-word'], [$transport->getUsername(), $transport->getPassword()]);
        $this->assertInstanceOf(TlsOnlyEsmtpTransport::class, $transport);
    }

    public function test_a_transport_without_a_socket_stream_is_refused_instead_of_left_unsecured(): void
    {
        $this->expectException(LogicException::class);

        $transport = new class extends EsmtpTransport
        {
            public bool $constructed = false;

            public function getStream(): AbstractStream
            {
                return $this->constructed ? new ProcessStream : parent::getStream();
            }
        };
        $transport->constructed = true;

        (new SmtpSenderTransportFactory)->secure($transport, new CheckedHost('smtp.example.test', self::PUBLIC_IP, true));
    }

    public function test_a_sender_config_never_serializes_its_password(): void
    {
        $config = $this->config();

        $this->assertStringNotContainsString('pass-word', (string) json_encode($config));
        $this->assertStringNotContainsString('pass-word', print_r($config, true));
        $this->expectException(LogicException::class);
        serialize($config);
    }

    public function test_an_ipv6_address_is_bracketed_for_the_socket(): void
    {
        $transport = (new SmtpSenderTransportFactory)->make($this->config(), new CheckedHost('smtp.example.test', '2606:4700::1111', true));

        $this->assertSame('[2606:4700::1111]', $transport->getStream()->getHost());
    }

    public function test_the_mailer_sends_from_the_accounts_own_address_to_the_checked_address(): void
    {
        $this->sender();

        app(AccountMailer::class)->mailer($this->account->id)->raw('Body', fn (Message $m) => $m->to('lead@example.test')->subject('Hi'));

        $sent = $this->sentOnly();
        $this->assertSame(['lead@example.test'], $this->recipients($sent));
        $from = $sent->getOriginalMessage()->getFrom()[0];
        $this->assertSame(['hi@example.test', 'Studio Example'], [$from->getAddress(), $from->getName()]);
        $this->assertSame(self::PUBLIC_IP, $this->connections[0]->address);
    }

    public function test_the_mailer_applies_the_redirect_address_and_its_name(): void
    {
        config(['mail.to.address' => 'safe@example.test', 'mail.to.name' => 'Internal']);
        $this->sender();

        app(AccountMailer::class)->mailer($this->account->id)->raw('Body', fn (Message $m) => $m->to('lead@example.test')->cc('copy@example.test')->subject('Hi'));

        $sent = $this->sentOnly();
        $this->assertSame(['safe@example.test'], $this->recipients($sent));
        $to = $sent->getOriginalMessage()->getTo();
        $this->assertSame([['safe@example.test', 'Internal']], array_map(fn (Address $a): array => [$a->getAddress(), $a->getName()], $to));
        $this->assertSame([], $sent->getOriginalMessage()->getCc());
    }

    public function test_the_mailer_refuses_in_the_demo(): void
    {
        config(['app.demo' => true]);
        $this->sender();

        $this->expectExceptionObject(SenderUnavailable::demo());

        app(AccountMailer::class)->mailer($this->account->id);
    }

    public function test_the_host_is_checked_again_on_every_send(): void
    {
        $this->sender();
        app(AccountMailer::class)->mailer($this->account->id);

        $this->dns['smtp.example.test'] = ['10.0.0.5'];

        $this->expectException(SenderUnavailable::class);
        app(AccountMailer::class)->mailer($this->account->id);
    }

    public function test_an_account_without_an_enabled_sender_has_no_mailer(): void
    {
        $this->assertRefused(SenderUnavailable::NONE);

        $this->sender(['is_enabled' => false]);
        $this->assertRefused(SenderUnavailable::NONE);
    }

    public function test_another_accounts_sender_is_never_used(): void
    {
        $this->sender(['account_id' => Account::factory()->create()->id]);

        $this->assertRefused(SenderUnavailable::NONE);
    }

    public function test_a_password_stored_under_another_app_key_is_refused(): void
    {
        $row = $this->sender();
        $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::table('integrations')->where('id', $row->id)->update(['api_key' => $foreign->encryptString('pass-word')]);

        $this->assertRefused(SenderUnavailable::UNREADABLE);
    }

    public function test_the_password_is_stored_encrypted_and_never_serialized(): void
    {
        $row = $this->sender();

        $this->assertStringNotContainsString('pass-word', (string) DB::table('integrations')->where('id', $row->id)->value('api_key'));
        $this->assertSame('pass-word', $row->fresh()->api_key);
        $this->assertStringNotContainsString('pass-word', $row->fresh()->toJson());
        $this->assertArrayNotHasKey('api_key', $row->fresh()->toArray());
    }

    public function test_a_failure_log_context_holds_the_class_and_code_only(): void
    {
        $context = AccountMailer::failureContext(7, new RuntimeException('535 auth failed for box@example.test pass-word', 535));

        $this->assertSame(['account' => 7, 'exception' => RuntimeException::class, 'code' => 535], $context);
    }

    private function assertRefused(string $reason): void
    {
        try {
            app(AccountMailer::class)->mailer($this->account->id);
            $this->fail('the mailer was built');
        } catch (SenderUnavailable $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function sender(array $overrides = []): Integration
    {
        return Integration::create([
            'account_id' => $this->account->id,
            'provider' => Integration::SMTP,
            'is_enabled' => true,
            'api_key' => 'pass-word',
            'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'username' => 'box@example.test', 'from_address' => 'hi@example.test', 'from_name' => 'Studio Example'],
            ...$overrides,
        ]);
    }

    private function config(int $port = 587): SenderConfig
    {
        return new SenderConfig('smtp.example.test', $port, 'box@example.test', 'pass-word', 'hi@example.test', 'Studio Example');
    }

    private function sentOnly(): SentMessage
    {
        $messages = $this->transport->messages();
        $this->assertCount(1, $messages);

        return $messages->first();
    }

    /** @return list<string> */
    private function recipients(SentMessage $sent): array
    {
        return array_map(fn (Address $a): string => $a->getAddress(), $sent->getEnvelope()->getRecipients());
    }
}

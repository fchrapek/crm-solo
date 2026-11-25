<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\TestMailSender;
use App\Models\Account;
use App\Models\Integration;
use App\Services\Agent\AgentAbilities;
use App\Services\Messaging\Sender\CheckedHost;
use App\Services\Messaging\Sender\HostResolver;
use App\Services\Messaging\Sender\SenderConfig;
use App\Services\Messaging\Sender\SenderTransportFactory;
use App\Support\SecretInput;
use App\Support\StdinSecretInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * The sender is set on the server with the password piped on stdin, never
 * typed as an argument, and a throttled test send reports a failure without
 * the server's words.
 */
final class MailSenderCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'pipe-only-s3cret';

    private ?string $piped = self::PASSWORD;

    /** @var array<string, list<string>> */
    private array $dns = ['smtp.example.test' => ['104.16.0.1']];

    private TransportInterface $transport;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
        config(['app.demo' => false, 'mail.to.address' => null]);
        $this->account = Account::factory()->create();

        $this->app->bind(SecretInput::class, fn (): SecretInput => new class($this->piped) implements SecretInput
        {
            public function __construct(private ?string $line) {}

            public function line(): ?string
            {
                return $this->line;
            }
        });
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
        $this->app->bind(SenderTransportFactory::class, fn (): SenderTransportFactory => new class($this->transport) implements SenderTransportFactory
        {
            public function __construct(private TransportInterface $transport) {}

            public function make(SenderConfig $sender, CheckedHost $host): TransportInterface
            {
                return $this->transport;
            }
        });
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badFields(): array
    {
        return [
            'port 25' => [['--port' => '25'], 'port must be'],
            'an address as host' => [['--host' => '104.16.0.1'], 'server name'],
            'a single-label host' => [['--host' => 'mailpit'], 'server name'],
            'not an email as from' => [['--from' => 'nobody'], 'from address'],
            'a line break in the from name' => [['--from-name' => "Name\r\nBcc: x@example.test"], 'from name'],
            'no username' => [['--username' => ''], 'username'],
        ];
    }

    public function test_the_sender_is_stored_with_the_password_from_stdin_encrypted(): void
    {
        $this->assertSame(0, Artisan::call('mail-sender:set', $this->setOptions()));
        $this->assertStringContainsString('stored encrypted', Artisan::output());
        $this->assertStringNotContainsString(self::PASSWORD, Artisan::output());

        $row = Integration::query()->where('account_id', $this->account->id)->where('provider', Integration::SMTP)->sole();
        $this->assertTrue($row->is_enabled);
        $this->assertSame(self::PASSWORD, $row->api_key);
        $this->assertStringNotContainsString(self::PASSWORD, (string) DB::table('integrations')->value('api_key'));
        $this->assertStringNotContainsString(self::PASSWORD, (string) DB::table('integrations')->value('settings'));
        $this->assertSame(
            ['host' => 'smtp.example.test', 'port' => 587, 'username' => 'box@example.test', 'from_address' => 'hi@example.test', 'from_name' => 'Studio Example'],
            $row->settings,
        );
    }

    public function test_the_password_is_never_an_argument_or_an_option(): void
    {
        $definition = Artisan::all()['mail-sender:set']->getDefinition();

        $this->assertSame([], array_keys($definition->getArguments()));
        foreach ($definition->getOptions() as $name => $option) {
            $this->assertStringNotContainsString('file', $name);
            if (str_contains($name, 'pass')) {
                $this->assertSame('keep-password', $name);
                $this->assertFalse($option->acceptValue(), '--keep-password is a switch and takes no value');
            }
        }
    }

    public function test_the_sender_commands_are_not_agent_verbs(): void
    {
        $commands = array_column(AgentAbilities::VERBS, 0);

        $this->assertNotContains('mail-sender:set', $commands);
        $this->assertNotContains('mail-sender:test', $commands);
    }

    public function test_keep_password_keeps_the_stored_password_without_reading_stdin_and_changes_the_rest(): void
    {
        $this->set()->assertSuccessful();
        $this->piped = 'must-not-be-read';

        $this->set(['--from-name' => 'New Name', '--keep-password' => true])->assertSuccessful()->expectsOutputToContain('The stored password was kept.');

        $row = Integration::query()->where('provider', Integration::SMTP)->sole();
        $this->assertSame([self::PASSWORD, 'New Name'], [$row->api_key, $row->settings['from_name']]);
    }

    public function test_keep_password_with_nothing_stored_is_refused(): void
    {
        $this->set(['--keep-password' => true])->assertFailed()->expectsOutputToContain('No password is stored yet');

        $this->assertFalse(Integration::query()->where('provider', Integration::SMTP)->exists());
    }

    public function test_nothing_piped_without_keep_password_is_refused_and_keeps_the_stored_password(): void
    {
        $this->set()->assertSuccessful();
        $this->piped = null;

        $this->set(['--from-name' => 'New Name'])->assertFailed()->expectsOutputToContain('No password came in on stdin');

        $row = Integration::query()->where('provider', Integration::SMTP)->sole();
        $this->assertSame([self::PASSWORD, 'Studio Example'], [$row->api_key, $row->settings['from_name']]);
    }

    public function test_an_empty_pipe_is_refused(): void
    {
        $this->piped = '';

        $this->set()->assertFailed();

        $this->assertFalse(Integration::query()->where('provider', Integration::SMTP)->exists());
    }

    public function test_stdin_gives_one_line_without_its_break_and_refuses_several(): void
    {
        $this->assertSame('one line', (new StdinSecretInput($this->stream("one line\n")))->line());
        $this->assertSame('crlf', (new StdinSecretInput($this->stream("crlf\r\n")))->line());

        $this->expectException(InvalidArgumentException::class);
        (new StdinSecretInput($this->stream("host=x\npassword=y\n")))->line();
    }

    public function test_stdin_refuses_more_than_four_kilobytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new StdinSecretInput($this->stream(str_repeat('a', 5000))))->line();
    }

    /** @param  array<string, string>  $options */
    #[DataProvider('badFields')]
    public function test_a_bad_field_is_refused_and_nothing_is_stored(array $options, string $message): void
    {
        $this->set($options)->assertFailed()->expectsOutputToContain($message);

        $this->assertFalse(Integration::query()->where('provider', Integration::SMTP)->exists());
    }

    public function test_a_host_that_resolves_inside_the_network_is_refused_when_set(): void
    {
        $this->dns['smtp.example.test'] = ['104.16.0.1', '10.0.0.5'];

        $this->set()->assertFailed()->expectsOutputToContain('not public');

        $this->assertFalse(Integration::query()->where('provider', Integration::SMTP)->exists());
    }

    public function test_an_unknown_or_missing_account_is_refused(): void
    {
        $this->set(['--account' => '999'])->assertFailed();
        $this->set(['--account' => ''])->assertFailed();

        $this->assertFalse(Integration::query()->exists());
    }

    public function test_both_commands_refuse_in_the_demo_with_a_valid_sender_in_place(): void
    {
        $this->set()->assertSuccessful();
        config(['app.demo' => true]);

        $this->set(['--from-name' => 'Changed in the demo'])->assertFailed()->expectsOutputToContain('The demo has no senders.');
        $this->artisan('mail-sender:test', ['--account' => $this->account->id])->assertFailed()->expectsOutputToContain('The demo sends no email. Nothing was sent.');
        $this->artisan('mail-sender:test')->assertFailed()->expectsOutputToContain('The demo sends no email. Nothing was sent.');

        $this->assertSame('Studio Example', Integration::query()->where('provider', Integration::SMTP)->sole()->settings['from_name']);
        $this->assertCount(0, $this->transport->messages());
        $this->assertSame(0, RateLimiter::attempts(TestMailSender::throttleKey($this->account->id)));
    }

    public function test_a_test_send_goes_through_the_accounts_sender_to_its_own_address(): void
    {
        $this->set()->assertSuccessful();

        $this->artisan('mail-sender:test', ['--account' => $this->account->id])
            ->assertSuccessful()
            ->expectsOutputToContain('Test email sent to hi@example.test');

        $sent = $this->transport->messages()->sole();
        $this->assertSame('hi@example.test', $sent->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame('CRM Solo sender test', $sent->getOriginalMessage()->getSubject());
    }

    public function test_test_sends_are_throttled_per_account(): void
    {
        $this->set()->assertSuccessful();

        for ($i = 0; $i < 3; $i++) {
            $this->artisan('mail-sender:test', ['--account' => $this->account->id])->assertSuccessful();
        }
        $this->artisan('mail-sender:test', ['--account' => $this->account->id])->assertFailed()->expectsOutputToContain('Too many test sends');

        $this->assertCount(3, $this->transport->messages());
    }

    public function test_a_test_send_refused_before_sending_does_not_count_against_the_limit(): void
    {
        $this->set()->assertSuccessful();

        for ($i = 0; $i < 3; $i++) {
            $this->artisan('mail-sender:test', ['--account' => $this->account->id, '--to' => 'not-an-address'])->assertFailed();
        }
        $this->dns['smtp.example.test'] = ['10.0.0.5'];
        $this->artisan('mail-sender:test', ['--account' => $this->account->id])->assertFailed()->expectsOutputToContain('not public');
        $this->dns['smtp.example.test'] = ['104.16.0.1'];

        $this->assertSame(0, RateLimiter::attempts(TestMailSender::throttleKey($this->account->id)));
        $this->artisan('mail-sender:test', ['--account' => $this->account->id])->assertSuccessful();
    }

    public function test_a_failed_test_send_shows_a_generic_error_and_logs_the_class_and_code_only(): void
    {
        $this->set()->assertSuccessful();
        $this->transport = new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('535 5.7.8 authentication failed for box@example.test with '.'pipe-only-s3cret', 535);
            }

            public function __toString(): string
            {
                return 'failing';
            }
        };
        Log::spy();

        $this->assertSame(1, Artisan::call('mail-sender:test', ['--account' => $this->account->id]));
        $output = Artisan::output();
        $this->assertStringContainsString('The test email was not sent.', $output);
        $this->assertStringNotContainsString('535', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === [
            'account' => $this->account->id,
            'exception' => TransportException::class,
            'code' => 535,
        ]);
    }

    /** @param  array<string, string>  $options */
    private function set(array $options = []): PendingCommand
    {
        return $this->artisan('mail-sender:set', $this->setOptions($options));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function setOptions(array $overrides = []): array
    {
        return [
            '--account' => (string) $this->account->id,
            '--host' => 'smtp.example.test',
            '--port' => '587',
            '--username' => 'box@example.test',
            '--from' => 'hi@example.test',
            '--from-name' => 'Studio Example',
            ...$overrides,
        ];
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = tmpfile();
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}

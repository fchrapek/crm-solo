<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\IntakeController;
use App\Jobs\SendLeadIntakeNotice;
use App\Models\Account;
use App\Models\IntakeForm;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Services\Leads\FormIntake;
use App\Services\Messaging\Sender\CheckedHost;
use App\Services\Messaging\Sender\HostResolver;
use App\Services\Messaging\Sender\SenderConfig;
use App\Services\Messaging\Sender\SenderTransportFactory;
use Closure;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * A server webhook posts with its own secret, extras land in the note, and
 * every new lead's notice goes out through the account's own sender from a
 * job that holds only the intake id, or waits until a sender exists.
 */
final class LeadIntakeNotifyTest extends TestCase
{
    use RefreshDatabase;

    private const string ORIGIN = 'https://www.example.test';

    private const string KEY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

    private IntakeForm $form;

    private TransportInterface $transport;

    /** @var array<string, list<string>> */
    private array $dns = ['smtp.example.test' => ['104.16.0.1']];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.demo' => false, 'intake.ip_per_minute' => 1000, 'intake.ip_per_hour' => 1000, 'intake.per_minute' => 100, 'intake.per_hour' => 100, 'intake.webhook_per_minute' => 100, 'mail.to.address' => null]);
        Http::preventStrayRequests();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'www.example.test', 'action' => 'lead-intake'])]);

        $this->transport = new ArrayTransport;
        $this->app->bind(SenderTransportFactory::class, fn (): SenderTransportFactory => new class($this->transport) implements SenderTransportFactory
        {
            public function __construct(private TransportInterface $transport) {}

            public function make(SenderConfig $sender, CheckedHost $host): TransportInterface
            {
                return $this->transport;
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

        $this->form = $this->makeForm(Account::factory()->create());
    }

    /** @return array<string, array{string}> */
    public static function refusedAuthorizations(): array
    {
        return [
            'a wrong secret' => ['Bearer crmhook_wrong'],
            'another scheme' => ['Basic dXNlcjpwYXNz'],
            'an empty bearer' => ['Bearer '],
            'Basic before Bearer' => ['Basic Bearer crmhook_x'],
            'two schemes in one value' => ['Bearer crmhook_x, Basic dXNlcg=='],
            'two tokens' => ['Bearer crmhook_x crmhook_y'],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function extrasOverTheCaps(): array
    {
        $many = [];
        for ($i = 1; $i <= 21; $i++) {
            $many["field{$i}"] = 'x';
        }

        return [
            '21 keys' => [$many],
            'a 41 character key' => [[str_repeat('k', 41) => 'x']],
            'a key with spaces' => [['my field' => 'x']],
            'a 1001 character value' => [['note' => str_repeat('a', 1001)]],
            'a list of 11' => [['needs' => array_fill(0, 11, 'x')]],
            'an object' => [['needs' => ['a' => 'x']]],
            'a list holding a list' => [['needs' => [['x']]]],
            'a control character' => [['note' => "a\u{0000}b"]],
        ];
    }

    /** @return array<string, array{Closure(self): void}> */
    public static function finalRefusals(): array
    {
        return [
            'the demo' => [fn (self $test) => config(['app.demo' => true])],
            'a host inside the network' => [fn (self $test) => $test->pointHostAt('10.0.0.5')],
            'no notify-to address' => [fn (self $test) => $test->clearNotifyTo()],
        ];
    }

    /** @return array<string, array{array<string, mixed>|string}> */
    public static function controlCharacterExtras(): array
    {
        return [
            'a line feed' => [['note' => "a\nb"]],
            'a carriage return' => [['note' => "a\rb"]],
            'a tab' => [['note' => "a\tb"]],
            'an empty object' => ['{"needs": {}}'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unmailableAddresses(): array
    {
        return [
            'a tab inside' => ["ewa\t@example.test"],
            'a comment the mailer refuses' => ['ewa(comment)@example.test'],
        ];
    }

    public function test_a_webhook_with_its_secret_posts_without_origin_or_turnstile(): void
    {
        $secret = $this->webhookSecret();

        $this->webhook($this->payload(), $secret)
            ->assertCreated()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->assertSame($this->form->account_id, Lead::query()->sole()->account_id);
        Http::assertNothingSent();
    }

    #[DataProvider('refusedAuthorizations')]
    public function test_a_wrong_authorization_is_401_and_never_falls_back_to_the_browser_checks(string $header): void
    {
        $this->webhookSecret();

        $this->postRaw((string) json_encode($this->payload()), ['HTTP_AUTHORIZATION' => $header, 'HTTP_ORIGIN' => self::ORIGIN])
            ->assertUnauthorized();

        $this->assertSame(0, Lead::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_form_without_a_webhook_secret_refuses_every_authorization(): void
    {
        $this->webhook($this->payload(), 'crmhook_anything')->assertUnauthorized();
    }

    public function test_a_webhook_without_a_key_gets_no_replay_protection_and_with_one_it_does(): void
    {
        $secret = $this->webhookSecret();
        $payload = $this->payload();
        unset($payload['idempotency_key']);

        $this->webhook($payload, $secret)->assertCreated();
        $this->webhook($payload, $secret)->assertCreated();
        $this->webhook($this->payload(), $secret)->assertCreated();
        $this->webhook($this->payload(), $secret)->assertOk();

        $this->assertSame(3, Lead::query()->count());
    }

    public function test_the_webhook_secret_is_shown_once_stored_as_a_hash_and_rotation_retires_the_old_one(): void
    {
        $first = $this->webhookSecret();
        $this->assertStringStartsWith('crmhook_', $first);
        $this->assertSame(hash('sha256', $first), $this->form->refresh()->webhook_secret_hash);
        $this->assertStringNotContainsString($first, (string) json_encode(DB::table('intake_forms')->first()));

        $second = $this->webhookSecret();
        $this->assertNotSame($first, $second);
        $this->webhook($this->payload(), $first)->assertUnauthorized();
        $this->webhook($this->payload(), $second)->assertCreated();

        $this->artisan('intake-form:webhook-secret', ['--account' => $this->form->account_id, '--form' => $this->form->id, '--clear' => true])->assertSuccessful();
        $this->webhook([...$this->payload(), 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2'], $second)->assertUnauthorized();
    }

    public function test_the_webhook_secret_command_refuses_in_the_demo_and_for_another_accounts_form(): void
    {
        $other = Account::factory()->create();
        $this->artisan('intake-form:webhook-secret', ['--account' => $other->id, '--form' => $this->form->id])->assertFailed();

        config(['app.demo' => true]);
        $this->artisan('intake-form:webhook-secret', ['--account' => $this->form->account_id, '--form' => $this->form->id])->assertFailed();

        $this->assertNull($this->form->refresh()->webhook_secret_hash);
    }

    public function test_extras_land_in_the_lead_note_and_control_fields_never_do(): void
    {
        $this->post_([
            ...$this->payload(),
            'needs' => ['website', 'seo'],
            'budget' => '15k-30k',
            'timing' => 'asap',
            'newsletter' => false,
            'cf-ray' => 'abc123',
            'CF-Connecting-IP' => '203.0.113.9',
        ])->assertCreated();

        $notes = (string) Lead::query()->sole()->notes;
        $this->assertStringContainsString("Dodatkowe pola:\nneeds: website, seo\nbudget: 15k-30k\ntiming: asap\nnewsletter: false", $notes);
        foreach (['cf-ray', 'abc123', 'CF-Connecting-IP', '203.0.113.9', self::KEY, 'token-from-widget'] as $control) {
            $this->assertStringNotContainsString($control, $notes);
        }
    }

    public function test_the_same_key_with_other_extras_is_a_409(): void
    {
        $this->post_([...$this->payload(), 'budget' => 'under-5k'])->assertCreated();

        $this->post_([...$this->payload(), 'budget' => '30k-plus'])->assertConflict();
    }

    /** @param  array<string, mixed>  $extras */
    #[DataProvider('extrasOverTheCaps')]
    public function test_extras_over_the_caps_are_a_422(array $extras): void
    {
        $this->post_([...$this->payload(), ...$extras])->assertUnprocessable()->assertJsonPath('errors', ['extras']);

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_the_notice_goes_through_the_accounts_sender_to_notify_to_and_replies_to_the_visitor(): void
    {
        $this->sender();

        $this->post_([...$this->payload(), 'needs' => ['website']])->assertCreated();

        $email = $this->sentOnly();
        $this->assertSame('hi@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('box@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame(['ewa@example.test', 'Ewa Example'], [$email->getReplyTo()[0]->getAddress(), $email->getReplyTo()[0]->getName()]);
        $this->assertSame('New lead: Ewa Example', $email->getSubject());
        $body = (string) $email->getTextBody();
        foreach (['Contact drawer', 'Ewa Example', 'ewa@example.test', 'Example Studio', 'We need a new site.', 'needs: website', '/leads/'] as $part) {
            $this->assertStringContainsString($part, $body);
        }

        $intake = LeadIntake::query()->sole();
        $this->assertSame([LeadIntake::SENT, 1], [$intake->notice_state, $intake->notify_attempts]);
        $this->assertNotNull($intake->notified_at);
    }

    public function test_the_notice_names_the_earlier_lead_of_the_same_address_in_the_forms_account_only(): void
    {
        $this->sender();
        $foreign = Lead::create(['account_id' => Account::factory()->create()->id, 'pipeline' => 'filipchrapek', 'source' => 'www-form', 'name' => 'Ewa', 'email' => 'ewa@example.test']);
        $this->post_($this->payload())->assertCreated();
        $first = Lead::query()->where('account_id', $this->form->account_id)->sole();

        $this->post_([...$this->payload(), 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'email' => 'EWA@example.test'])->assertCreated();

        $messages = $this->transport->messages();
        $this->assertCount(2, $messages);
        $firstBody = (string) $messages->first()->getOriginalMessage()->getTextBody();
        $secondBody = (string) $messages->last()->getOriginalMessage()->getTextBody();
        $this->assertStringNotContainsString('This address already wrote', $firstBody);
        $this->assertStringContainsString("/leads/{$first->id}/edit", $secondBody);
        $this->assertStringNotContainsString("/leads/{$foreign->id}/edit", $firstBody.$secondBody);
    }

    public function test_the_notice_uses_the_forms_notify_to_at_send_time(): void
    {
        Queue::fake();
        $this->post_($this->payload())->assertCreated();
        $this->sender();
        $this->form->update(['notify_to' => 'later@example.test']);

        (new SendLeadIntakeNotice(LeadIntake::query()->sole()->id))->handle(app(\App\Services\Messaging\Sender\AccountMailer::class));

        $this->assertSame('later@example.test', $this->sentOnly()->getTo()[0]->getAddress());
    }

    public function test_without_a_sender_the_notice_waits_and_is_not_counted(): void
    {
        $this->post_($this->payload())->assertCreated();

        $intake = LeadIntake::query()->sole();
        $this->assertSame([LeadIntake::WAITING, 0], [$intake->notice_state, $intake->notify_attempts]);
        $this->assertCount(0, $this->transport->messages());
    }

    public function test_a_sender_password_this_app_key_cannot_read_leaves_the_notice_waiting(): void
    {
        $row = $this->sender();
        DB::table('integrations')->where('id', $row->id)->update(['api_key' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('x')]);

        $this->post_($this->payload())->assertCreated();

        $this->assertSame(LeadIntake::WAITING, LeadIntake::query()->sole()->notice_state);
    }

    /** @param  Closure(self): void  $arrange */
    #[DataProvider('finalRefusals')]
    public function test_a_refusal_that_retrying_cannot_fix_marks_the_notice_failed(Closure $arrange): void
    {
        Queue::fake();
        $this->sender();
        $this->post_($this->payload())->assertCreated();
        $arrange($this);

        $this->runJob();

        $this->assertSame(LeadIntake::FAILED, LeadIntake::query()->sole()->notice_state);
        $this->assertCount(0, $this->transport->messages());
    }

    public function pointHostAt(string $address): void
    {
        $this->app['env'] = 'production';
        $this->dns['smtp.example.test'] = [$address];
    }

    public function clearNotifyTo(): void
    {
        $this->form->update(['notify_to' => null]);
    }

    public function test_a_transport_failure_is_retried_logged_by_class_and_code_and_fails_after_the_last_try(): void
    {
        Queue::fake();
        $this->sender();
        $this->post_($this->payload())->assertCreated();
        $this->transport = $this->failingTransport(421);
        Log::spy();

        try {
            $this->runJob();
            $this->fail('the job did not rethrow');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('pass-word', $e->getMessage());
            $this->assertStringNotContainsString('421 4.7.0', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame([LeadIntake::WAITING, 1], [LeadIntake::query()->sole()->notice_state, LeadIntake::query()->sole()->notify_attempts]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Lead notice send failed.'
            && array_keys($context) === ['intake', 'exception', 'code'] && $context['code'] === 421);

        (new SendLeadIntakeNotice(LeadIntake::query()->sole()->id))->failed(new RuntimeException('x'));
        $this->assertSame(LeadIntake::FAILED, LeadIntake::query()->sole()->notice_state);
    }

    public function test_a_failed_dispatch_still_answers_201_and_leaves_the_notice_waiting(): void
    {
        $this->sender();
        config(['queue.default' => 'unreachable', 'queue.connections.unreachable' => ['driver' => 'unreachable']]);

        $this->post_($this->payload())->assertCreated();

        $this->assertSame(1, Lead::query()->count());
        $this->assertSame(LeadIntake::WAITING, LeadIntake::query()->sole()->notice_state);
    }

    public function test_a_deleted_lead_or_a_notice_older_than_seven_days_is_skipped(): void
    {
        Queue::fake();
        $this->sender();
        $this->post_($this->payload())->assertCreated();
        Lead::query()->sole()->delete();
        $this->runJob();
        $this->assertSame(LeadIntake::SKIPPED, LeadIntake::query()->sole()->notice_state);

        $this->post_([...$this->payload(), 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2'])->assertCreated();
        $old = LeadIntake::query()->latest('id')->first();
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        (new SendLeadIntakeNotice($old->id))->handle(app(\App\Services\Messaging\Sender\AccountMailer::class));

        $this->assertSame(LeadIntake::SKIPPED, $old->refresh()->notice_state);
        $this->assertCount(0, $this->transport->messages());
    }

    public function test_the_job_holds_the_intake_id_only_and_is_unique_per_intake(): void
    {
        Queue::fake();
        $this->post_($this->payload())->assertCreated();
        $id = LeadIntake::query()->sole()->id;

        Queue::assertPushed(SendLeadIntakeNotice::class, fn (SendLeadIntakeNotice $job): bool => $job->intakeId === $id);
        $job = new SendLeadIntakeNotice($id);
        $serialized = serialize($job);
        foreach (['ewa@example.test', 'Ewa Example', 'hi@example.test'] as $value) {
            $this->assertStringNotContainsString($value, $serialized);
        }
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame((string) $id, $job->uniqueId());
        $this->assertSame([5, [60, 300, 900, 3600]], [$job->tries, $job->backoff()]);
    }

    public function test_notify_pending_sends_waiting_notices_of_accounts_with_a_sender_and_skips_old_ones(): void
    {
        Queue::fake();
        $other = $this->makeForm(Account::factory()->create());
        $this->sender();
        $due = $this->intakeAt($this->form, now()->subMinutes(30));
        $fresh = $this->intakeAt($this->form, now()->subMinutes(5));
        $stale = $this->intakeAt($this->form, now()->subDays(8));
        $noSender = $this->intakeAt($other, now()->subMinutes(30));
        $sent = $this->intakeAt($this->form, now()->subMinutes(30), LeadIntake::SENT);

        $this->artisan('lead-intake:notify-pending')->assertSuccessful();

        Queue::assertPushed(SendLeadIntakeNotice::class, 1);
        Queue::assertPushed(SendLeadIntakeNotice::class, fn (SendLeadIntakeNotice $job): bool => $job->intakeId === $due->id);
        $this->assertSame(LeadIntake::SKIPPED, $stale->refresh()->notice_state);
        $this->assertSame([LeadIntake::WAITING, LeadIntake::WAITING, LeadIntake::SENT], [$fresh->refresh()->notice_state, $noSender->refresh()->notice_state, $sent->refresh()->notice_state]);
    }

    public function test_a_sender_added_later_releases_the_waiting_notice(): void
    {
        $this->post_($this->payload())->assertCreated();
        $this->assertSame(LeadIntake::WAITING, LeadIntake::query()->sole()->notice_state);

        $this->travel(11)->minutes();
        $this->sender();
        $this->artisan('lead-intake:notify-pending')->assertSuccessful();

        $this->assertSame(LeadIntake::SENT, LeadIntake::query()->sole()->notice_state);
        $this->assertCount(1, $this->transport->messages());
    }

    public function test_notify_pending_and_status_refuse_in_the_demo(): void
    {
        Queue::fake();
        $this->sender();
        $this->intakeAt($this->form, now()->subMinutes(30));
        config(['app.demo' => true]);

        $this->artisan('lead-intake:notify-pending')->assertFailed();
        $this->artisan('lead-intake:status')->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_status_counts_waiting_and_failed_notices_per_form(): void
    {
        $other = $this->makeForm(Account::factory()->create());
        $this->intakeAt($this->form, now(), LeadIntake::WAITING);
        $this->intakeAt($this->form, now(), LeadIntake::WAITING);
        $this->intakeAt($this->form, now(), LeadIntake::FAILED);
        $this->intakeAt($other, now(), LeadIntake::SENT);

        $this->assertSame(0, Artisan::call('lead-intake:status', ['--account' => $this->form->account_id]));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression("/\\|\\s*{$this->form->id}\\s*\\|\\s*Contact drawer\\s*\\|\\s*yes\\s*\\|\\s*2\\s*\\|\\s*1\\s*\\|\\s*0\\s*\\|\\s*0\\s*\\|/", $output);
        $this->assertSame(1, mb_substr_count($output, 'Contact drawer'), "only the named account's form is listed");
    }

    public function test_status_headings_in_polish_name_the_notices_in_their_own_words(): void
    {
        app()->setLocale('pl');

        Artisan::call('lead-intake:status');

        $this->assertMatchesRegularExpression('/Czekają\s*\|\s*Nieudane\s*\|\s*Wysłane\s*\|\s*Pominięte/u', Artisan::output());
    }

    public function test_the_bearer_is_one_value_with_any_case_scheme_and_one_token(): void
    {
        $request = fn (array $values): \Illuminate\Http\Request => tap(\Illuminate\Http\Request::create('/intake/x', 'POST'), fn ($r) => $r->headers->set('Authorization', $values));

        $this->assertSame('crmhook_x', IntakeController::bearer($request(['  bearer   crmhook_x  '])));
        $this->assertSame('crmhook_x', IntakeController::bearer($request(['BEARER crmhook_x'])));
        $this->assertNull(IntakeController::bearer($request(['Bearer crmhook_x', 'Bearer crmhook_x'])));
        $this->assertNull(IntakeController::bearer($request(['Basic Bearer crmhook_x'])));
    }

    public function test_a_webhook_keeps_a_website_field_as_data_and_has_no_honeypot(): void
    {
        $secret = $this->webhookSecret();

        $this->webhook([...$this->payload(), 'website' => 'https://client.example.test'], $secret)->assertCreated();

        $this->assertStringContainsString('website: https://client.example.test', (string) Lead::query()->sole()->notes);
    }

    /** @param  array<string, mixed>|string  $extras */
    #[DataProvider('controlCharacterExtras')]
    public function test_extras_refuse_control_characters_and_objects(array|string $extras): void
    {
        $body = is_string($extras)
            ? mb_substr((string) json_encode($this->payload()), 0, -1).','.mb_substr($extras, 1)
            : (string) json_encode([...$this->payload(), ...$extras]);

        $this->postRaw($body, ['HTTP_ORIGIN' => self::ORIGIN])->assertUnprocessable()->assertJsonPath('errors', ['extras']);

        $this->assertSame(0, Lead::query()->count());
    }

    #[DataProvider('unmailableAddresses')]
    public function test_an_address_the_mailer_would_refuse_is_a_422_before_any_lead(string $email): void
    {
        $this->post_([...$this->payload(), 'email' => $email])->assertUnprocessable()->assertJsonPath('errors', ['email']);

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_failing_query_in_the_job_is_rethrown_without_its_message_or_visitor_data(): void
    {
        Queue::fake();
        $this->sender();
        Lead::create(['account_id' => $this->form->account_id, 'pipeline' => 'filipchrapek', 'source' => 'www-form', 'name' => 'Ewa', 'email' => 'ewa@example.test']);
        $this->post_($this->payload())->assertCreated();
        $reads = 0;
        // The second lead read is the earlier-lead lookup; fail it the way a query carrying the address fails.
        Lead::retrieved(function () use (&$reads): void {
            if (++$reads === 2) {
                throw new QueryException('sqlite', 'select * from "leads" where LOWER(email) = ?', ['ewa@example.test'], new PDOException('disk I/O error'));
            }
        });
        Log::spy();

        try {
            $this->runJob();
            $this->fail('the job did not rethrow');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('ewa@example.test', $e->getMessage());
            $this->assertStringNotContainsString('select', mb_strtolower($e->getMessage()));
            $this->assertNull($e->getPrevious());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => array_keys($context) === ['intake', 'exception', 'code']
            && ! str_contains((string) json_encode($context), 'ewa@example.test'));
    }

    public function test_a_host_that_does_not_resolve_is_retried_and_the_notice_keeps_waiting(): void
    {
        Queue::fake();
        $this->sender();
        $this->post_($this->payload())->assertCreated();
        $this->dns['smtp.example.test'] = [];

        try {
            $this->runJob();
            $this->fail('the job did not ask for a retry');
        } catch (RuntimeException) {
        }

        $intake = LeadIntake::query()->sole();
        $this->assertSame([LeadIntake::WAITING, 1], [$intake->notice_state, $intake->notify_attempts]);
    }

    public function test_a_refused_login_fails_the_accounts_waiting_notices_once_and_retry_failed_sends_them_again(): void
    {
        Queue::fake();
        $this->sender();
        $other = $this->makeForm(Account::factory()->create());
        $first = $this->intakeAt($this->form, now()->subMinutes(30));
        $second = $this->intakeAt($this->form, now()->subMinutes(30));
        $foreign = $this->intakeAt($other, now()->subMinutes(30));
        $this->transport = $this->failingTransport(535);
        $pushed = Queue::pushed(SendLeadIntakeNotice::class)->count();

        (new SendLeadIntakeNotice($first->id))->handle(app(\App\Services\Messaging\Sender\AccountMailer::class));

        $this->assertSame(['failed', 'auth'], [$second->refresh()->notice_state, $second->failure_reason]);
        $this->assertSame([LeadIntake::FAILED, 1], [$first->refresh()->notice_state, $first->notify_attempts]);
        $this->assertSame(LeadIntake::WAITING, $foreign->refresh()->notice_state);

        $this->artisan('lead-intake:notify-pending')->assertSuccessful();
        $this->assertSame($pushed, Queue::pushed(SendLeadIntakeNotice::class)->count());

        $this->transport = new ArrayTransport;
        $this->travel(11)->minutes();
        $this->artisan('lead-intake:notify-pending', ['--retry-failed' => true])->assertSuccessful();
        $this->assertSame($pushed + 2, Queue::pushed(SendLeadIntakeNotice::class)->count());
        $this->assertSame([LeadIntake::WAITING, null, 0], [$second->refresh()->notice_state, $second->failure_reason, $second->notify_attempts]);
    }

    public function test_retry_failed_leaves_notices_older_than_seven_days_alone(): void
    {
        Queue::fake();
        $this->sender();
        $old = $this->intakeAt($this->form, now()->subDays(8), LeadIntake::FAILED);

        $this->artisan('lead-intake:notify-pending', ['--retry-failed' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(LeadIntake::SKIPPED, $old->refresh()->notice_state);
    }

    public function test_a_real_queue_holds_one_job_per_intake_however_often_it_is_reconciled(): void
    {
        config(['queue.default' => 'database']);
        $this->sender();
        $this->post_($this->payload())->assertCreated();
        $this->assertSame(1, DB::table('jobs')->count());

        $this->travel(11)->minutes();
        Artisan::call('lead-intake:notify-pending');
        $this->assertStringContainsString('sent to the queue: 0', Artisan::output());
        Artisan::call('lead-intake:notify-pending');

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_refused_enqueue_frees_the_lock_so_the_reconcile_queues_it_later(): void
    {
        $this->sender();
        config(['queue.default' => 'unreachable', 'queue.connections.unreachable' => ['driver' => 'unreachable']]);
        $this->post_($this->payload())->assertCreated();

        config(['queue.default' => 'database']);
        $this->travel(11)->minutes();
        $this->artisan('lead-intake:notify-pending')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_lock_expires_after_the_retry_span_so_a_lost_job_is_queued_again(): void
    {
        $this->assertGreaterThan(60 + 300 + 900 + 3600, (new SendLeadIntakeNotice(1))->uniqueFor);
        config(['queue.default' => 'database']);
        $this->sender();
        $this->post_($this->payload())->assertCreated();
        DB::table('jobs')->delete();

        $this->travel(7201)->seconds();
        $this->artisan('lead-intake:notify-pending')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_rolled_back_write_sends_no_notice(): void
    {
        $this->sender();
        $fields = ['name' => 'Ewa', 'email' => 'ewa@example.test', 'message' => 'Hi', 'company' => null, 'lang' => null, 'page_url' => null, 'extras' => []];

        try {
            DB::transaction(function () use ($fields): void {
                app(FormIntake::class)->record($this->form, $fields, self::KEY);

                throw new RuntimeException('later step failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertCount(0, $this->transport->messages());
        $this->assertSame(0, LeadIntake::query()->count());
    }

    public function test_running_the_job_again_after_a_send_sends_nothing_more(): void
    {
        Queue::fake();
        $this->sender();
        $this->post_($this->payload())->assertCreated();

        $this->runJob();
        $this->runJob();

        $this->assertCount(1, $this->transport->messages());
    }

    public function test_a_second_reconcile_is_skipped_while_one_runs(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'lead-intake:notify-pending'));
        $this->assertNotNull($event);

        $this->assertFalse($event->shouldSkipDueToOverlapping());
        $event->mutex->create($event);

        $this->assertTrue($event->shouldSkipDueToOverlapping());
    }

    public function test_a_webhook_has_its_own_per_form_limit(): void
    {
        config(['intake.webhook_per_minute' => 2, 'intake.per_minute' => 100]);
        $secret = $this->webhookSecret();
        $payload = $this->payload();
        unset($payload['idempotency_key']);

        $this->webhook($payload, $secret)->assertCreated();
        $this->webhook($payload, $secret)->assertCreated();
        $this->webhook($payload, $secret)->assertTooManyRequests();
        $this->post_($this->payload())->assertCreated();
    }

    public function test_junk_authorization_headers_never_use_up_the_webhook_budget(): void
    {
        config(['intake.webhook_per_minute' => 1]);
        $secret = $this->webhookSecret();
        $payload = $this->payload();
        unset($payload['idempotency_key']);

        for ($i = 0; $i < 3; $i++) {
            $this->webhook($payload, 'crmhook_junk')->assertUnauthorized();
        }

        $this->webhook($payload, $secret)->assertCreated();
        $this->webhook($payload, $secret)->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_the_per_ip_form_limit_holds_a_wrong_webhook_secret_and_not_the_right_one(): void
    {
        config(['intake.per_minute' => 2]);
        $secret = $this->webhookSecret();
        $payload = $this->payload();
        unset($payload['idempotency_key']);

        $this->webhook($payload, 'crmhook_junk')->assertUnauthorized();
        $this->webhook($payload, 'crmhook_junk')->assertUnauthorized();

        $this->webhook($payload, 'crmhook_junk')->assertTooManyRequests();
        $this->webhook($payload, $secret)->assertCreated();
    }

    public function test_a_wrong_secret_answered_by_the_form_limit_still_uses_up_the_callers_refusals(): void
    {
        config(['intake.per_minute' => 1, 'intake.ip_per_minute' => 3]);
        $secret = $this->webhookSecret();
        $payload = $this->payload();
        unset($payload['idempotency_key']);

        $this->webhook($payload, 'crmhook_junk')->assertUnauthorized();
        $this->webhook($payload, 'crmhook_junk')->assertTooManyRequests();
        $this->webhook($payload, 'crmhook_junk')->assertTooManyRequests();

        // Three guesses spent the caller's refusals, so the answer no longer tells a right secret from a wrong one.
        $this->webhook($payload, $secret)->assertTooManyRequests();
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_push_refused_at_commit_frees_the_lock_so_the_reconcile_queues_it(): void
    {
        $this->sender();
        $intake = $this->intakeAt($this->form, now()->subMinutes(30));
        Queue::extend('refusing', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config)
            {
                return new class(DB::connection(), 'jobs') extends DatabaseQueue
                {
                    protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
                    {
                        throw new RuntimeException('queue down at commit');
                    }
                };
            }
        });
        config(['queue.default' => 'refusing', 'queue.connections.refusing' => ['driver' => 'refusing']]);

        Log::spy();

        DB::transaction(function () use ($intake): void {
            $this->assertFalse(SendLeadIntakeNotice::enqueue($intake->id), 'nothing is pushed before the commit');
        });

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Lead notice dispatch failed.' && $context['intake'] === $intake->id);

        config(['queue.default' => 'database']);
        $this->artisan('lead-intake:notify-pending')->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_the_crm_link_comes_before_the_visitors_text(): void
    {
        $this->sender();
        $this->post_($this->payload())->assertCreated();

        $body = (string) $this->sentOnly()->getTextBody();
        $this->assertLessThan(mb_strpos($body, 'We need a new site.'), mb_strpos($body, '/leads/'));
    }

    private function runJob(): void
    {
        (new SendLeadIntakeNotice(LeadIntake::query()->sole()->id))->handle(app(\App\Services\Messaging\Sender\AccountMailer::class));
    }

    private function intakeAt(IntakeForm $form, DateTimeInterface $at, string $state = LeadIntake::WAITING): LeadIntake
    {
        $lead = Lead::create(['account_id' => $form->account_id, 'pipeline' => 'filipchrapek', 'source' => 'www-form', 'name' => 'Ewa', 'email' => 'ewa@example.test']);
        $intake = LeadIntake::create([
            'account_id' => $form->account_id, 'intake_form_id' => $form->id, 'lead_id' => $lead->id,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'fingerprint' => str_repeat('0', 64), 'notice_state' => $state,
        ]);
        $intake->forceFill(['created_at' => $at])->save();

        return $intake;
    }

    private function failingTransport(int $code = 421): TransportInterface
    {
        return new class($code) implements TransportInterface
        {
            public function __construct(private int $code) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException("{$this->code} 4.7.0 refused for box@example.test with pass-word", $this->code);
            }

            public function __toString(): string
            {
                return 'failing';
            }
        };
    }

    private function sentOnly(): Email
    {
        $messages = $this->transport->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function sender(): Integration
    {
        return Integration::create([
            'account_id' => $this->form->account_id,
            'provider' => Integration::SMTP,
            'is_enabled' => true,
            'api_key' => 'pass-word',
            'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'username' => 'box@example.test', 'from_address' => 'box@example.test', 'from_name' => 'Studio'],
        ]);
    }

    private function webhookSecret(): string
    {
        $this->assertSame(0, Artisan::call('intake-form:webhook-secret', ['--account' => $this->form->account_id, '--form' => $this->form->id]));
        preg_match('/crmhook_[A-Za-z0-9]+/', Artisan::output(), $match);

        return $match[0] ?? '';
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'Ewa Example',
            'email' => 'ewa@example.test',
            'company' => 'Example Studio',
            'message' => 'We need a new site.',
            'idempotency_key' => self::KEY,
            'lang' => 'en',
            'cf-turnstile-response' => 'token-from-widget',
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function post_(array $payload): TestResponse
    {
        return $this->postRaw((string) json_encode($payload), ['HTTP_ORIGIN' => self::ORIGIN]);
    }

    /** @param  array<string, mixed>  $payload */
    private function webhook(array $payload, string $secret): TestResponse
    {
        return $this->postRaw((string) json_encode($payload), ['HTTP_AUTHORIZATION' => "Bearer {$secret}"]);
    }

    /** @param  array<string, string>  $server */
    private function postRaw(string $body, array $server): TestResponse
    {
        return $this->call('POST', "/intake/{$this->form->public_key}", server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7', ...$server], content: $body);
    }

    private function makeForm(Account $account): IntakeForm
    {
        return IntakeForm::create([
            'account_id' => $account->id,
            'name' => 'Contact drawer',
            'public_key' => IntakeForm::newPublicKey(),
            'allowed_origins' => [self::ORIGIN],
            'pipeline' => 'filipchrapek',
            'source' => 'www-form',
            'notify_to' => 'hi@example.test',
            'turnstile_secret' => 'form-turnstile-secret',
            'is_active' => true,
        ]);
    }
}

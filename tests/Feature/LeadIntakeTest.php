<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\GuardIntakeRequests;
use App\Models\Account;
use App\Models\IntakeForm;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\LeadStageEvent;
use App\Services\Leads\FormIntake;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A website form posts JSON to /intake/{key}; the form row alone decides the
 * account, pipeline and source, a replay key belongs to one form, and every
 * refusal is JSON with the CORS headers the browser needs to read it.
 */
final class LeadIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const string ORIGIN = 'https://www.example.test';

    private const string KEY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

    private IntakeForm $form;

    /** @var array<string, mixed> */
    private array|Closure $siteverify = ['success' => true, 'hostname' => 'www.example.test', 'action' => 'lead-intake'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.demo' => false, 'intake.ip_per_minute' => 100, 'intake.ip_per_hour' => 100, 'intake.per_minute' => 100, 'intake.per_hour' => 100, 'intake.new_leads_per_day' => 100]);
        $this->form = $this->makeForm(Account::factory()->create());
        $this->fakeSiteverify();
    }

    /** @return array<string, array{string, string}> */
    public static function invalidBodies(): array
    {
        $valid = self::validPayload();

        return [
            'no name' => [json_encode([...$valid, 'name' => '']), 'name'],
            'a nested name' => [json_encode([...$valid, 'name' => ['first' => 'Ewa']]), 'name'],
            'a control character in the name' => [json_encode([...$valid, 'name' => "Ewa\u{0007}"]), 'name'],
            'a bad email' => [json_encode([...$valid, 'email' => 'not-an-address']), 'email'],
            'no message' => [json_encode([...$valid, 'message' => null]), 'message'],
            'a key that is not a UUID' => [json_encode([...$valid, 'idempotency_key' => 'abc']), 'idempotency_key'],
            'an unknown language' => [json_encode([...$valid, 'lang' => 'de']), 'lang'],
            'a page on another site' => [json_encode([...$valid, 'page_url' => 'https://elsewhere.example.test/x']), 'page_url'],
            'a list' => ['[1, 2]', 'body'],
            'a scalar' => ['"hello"', 'body'],
            'broken JSON' => ['{"name": ', 'body'],
            'deeply nested JSON' => [json_encode([...$valid, 'company' => [[[[[[[[['x']]]]]]]]]]), 'body'],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function refusedSiteverify(): array
    {
        return [
            'siteverify answering 500' => [fn () => Http::response('down', 500)],
            'siteverify timing out' => [fn () => throw new ConnectionException('timed out')],
            'a failed check' => [['success' => false]],
            'another hostname' => [['success' => true, 'hostname' => 'evil.example.test', 'action' => 'lead-intake']],
            'another action' => [['success' => true, 'hostname' => 'www.example.test', 'action' => 'login']],
            'no hostname' => [['success' => true, 'action' => 'lead-intake']],
        ];
    }

    public function test_the_lead_note_is_polish_content_whatever_the_app_locale(): void
    {
        app()->setLocale('en');

        $this->post_($this->payload())->assertCreated();

        $notes = Lead::query()->sole()->notes;
        $this->assertStringContainsString('Język: en', $notes);
        $this->assertStringNotContainsString('Language:', $notes);
        $this->assertStringNotContainsString('Marketing consent', $notes);
    }

    public function test_a_submission_stores_a_lead_in_the_forms_account_pipeline_and_source(): void
    {
        $this->post_([...$this->payload(), 'account_id' => 999, 'pipeline' => 'kiwwwi', 'source' => 'ads', 'stage' => 'won', 'website' => ''])
            ->assertCreated()
            ->assertExactJson(['status' => 'received']);

        $lead = Lead::query()->sole();
        $this->assertSame(
            [$this->form->account_id, 'filipchrapek', 'www-form', 'Ewa Example', 'ewa@example.test', 'Example Studio', "form-{$this->form->id}-".self::KEY, null],
            [$lead->account_id, $lead->pipeline, $lead->source, $lead->name, $lead->email, $lead->company, $lead->external_ref, $lead->marketing_consent],
        );
        $this->assertStringContainsString('We need a new site.', $lead->notes);
        $this->assertStringContainsString('Język: en', $lead->notes);
        $this->assertStringContainsString('Strona: https://www.example.test/kontakt', $lead->notes);
        $this->assertStringContainsString('Zgoda marketingowa: niezbierana', $lead->notes);
        $this->assertStringContainsString("Formularz: {$this->form->name}", $lead->notes);
        foreach ([self::KEY, 'token-from-widget', 'website:', 'idempotency_key'] as $control) {
            $this->assertStringNotContainsString($control, $lead->notes);
        }

        $intake = LeadIntake::query()->sole();
        $this->assertSame([$lead->id, $this->form->id, LeadIntake::WAITING], [$intake->lead_id, $intake->intake_form_id, $intake->notice_state]);
        $this->assertSame(1, LeadStageEvent::query()->where('lead_id', $lead->id)->count());
        Http::assertSent(fn (HttpRequest $request): bool => $request['secret'] === 'form-turnstile-secret'
            && $request['response'] === 'token-from-widget'
            && $request['idempotency_key'] === self::KEY);
    }

    public function test_an_unknown_and_an_inactive_key_answer_the_same_404(): void
    {
        $unknown = $this->post_($this->payload(), str_repeat('x', 32));
        $this->form->update(['is_active' => false]);
        $inactive = $this->post_($this->payload());

        $unknown->assertNotFound();
        $inactive->assertNotFound();
        $this->assertSame($unknown->getContent(), $inactive->getContent());
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_replay_keys_and_leads_stay_inside_each_form_and_account(): void
    {
        $sameAccount = $this->makeForm(Account::query()->findOrFail($this->form->account_id));
        $otherAccount = $this->makeForm(Account::factory()->create());

        foreach ([$this->form, $sameAccount, $otherAccount] as $form) {
            $this->post_($this->payload(), $form->public_key)->assertCreated();
        }

        $this->assertSame(3, Lead::query()->count());
        $this->assertSame(2, Lead::query()->where('account_id', $this->form->account_id)->count());
        $this->assertSame(1, Lead::query()->where('account_id', $otherAccount->account_id)->count());
        $this->assertSame([$this->form->id, $sameAccount->id, $otherAccount->id], LeadIntake::query()->orderBy('id')->pluck('intake_form_id')->all());
    }

    public function test_a_replay_with_the_same_payload_answers_200_without_a_second_lead_or_siteverify(): void
    {
        $this->post_($this->payload())->assertCreated();
        $this->siteverify = ['success' => false];

        $this->post_([...$this->payload(), 'cf-turnstile-response' => 'expired-token'])->assertOk()->assertExactJson(['status' => 'received']);

        $this->assertSame(1, Lead::query()->count());
        $this->assertSame(1, LeadIntake::query()->count());
        Http::assertSentCount(1);
    }

    public function test_the_same_key_with_another_payload_is_a_409(): void
    {
        $this->post_($this->payload())->assertCreated();

        $this->post_([...$this->payload(), 'message' => 'Changed my mind.'])->assertConflict()->assertExactJson(['status' => 'conflict']);

        $this->assertSame(1, Lead::query()->count());
    }

    public function test_a_replay_never_brings_back_a_deleted_lead(): void
    {
        $this->post_($this->payload())->assertCreated();
        Lead::query()->sole()->delete();

        $this->post_($this->payload())->assertOk();

        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(1, Lead::withTrashed()->count());
    }

    public function test_a_filled_honeypot_answers_201_and_stores_nothing(): void
    {
        Log::spy();

        $this->post_([...$this->payload(), 'website' => 'https://spam.example.test'])->assertCreated()->assertExactJson(['status' => 'received']);

        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(0, LeadIntake::query()->count());
        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context === ['form' => $this->form->id]);
    }

    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_answers_json_422_naming_the_field_without_an_accept_header(string $body, string $field): void
    {
        $response = $this->postRaw($body);

        $response->assertUnprocessable();
        $this->assertSame('invalid', $response->json('status'));
        $this->assertContains($field, $response->json('errors'));
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_body_that_is_not_json_is_415(): void
    {
        $this->postRaw('name=Ewa', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'])
            ->assertStatus(415)
            ->assertExactJson(['status' => 'unsupported_type'])
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
    }

    public function test_an_oversized_body_is_413_with_and_without_content_length(): void
    {
        $big = (string) json_encode([...$this->payload(), 'message' => str_repeat('a', 17 * 1024)]);

        $this->postRaw($big)->assertStatus(413)->assertExactJson(['status' => 'too_large'])->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
        $this->postRaw((string) json_encode($this->payload()), ['CONTENT_LENGTH' => (string) (17 * 1024)])->assertStatus(413);
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_missing_or_foreign_origin_is_403_without_cors_headers(): void
    {
        $missing = $this->postRaw((string) json_encode($this->payload()), ['HTTP_ORIGIN' => null]);
        $foreign = $this->postRaw((string) json_encode($this->payload()), ['HTTP_ORIGIN' => 'https://evil.example.test']);

        foreach ([$missing, $foreign] as $response) {
            $response->assertForbidden()->assertExactJson(['status' => 'forbidden']);
            $response->assertHeaderMissing('Access-Control-Allow-Origin');
        }
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_the_preflight_answers_an_allowed_origin_and_refuses_a_foreign_one(): void
    {
        $this->call('OPTIONS', "/intake/{$this->form->public_key}", server: ['HTTP_ORIGIN' => self::ORIGIN, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertHeader('Access-Control-Allow-Methods', 'POST')
            ->assertHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->assertHeader('Access-Control-Max-Age', '600')
            ->assertHeader('Vary', 'Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $this->call('OPTIONS', "/intake/{$this->form->public_key}", server: ['HTTP_ORIGIN' => 'https://evil.example.test'])
            ->assertForbidden()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_the_cors_headers_ride_on_a_422_and_a_429(): void
    {
        config(['intake.per_minute' => 1]);

        $this->postRaw('[]')
            ->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertHeader('Access-Control-Expose-Headers', 'Retry-After');

        $limited = $this->post_($this->payload());
        $limited->assertTooManyRequests()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertHeader('Retry-After');
        $this->assertJson((string) $limited->getContent());
    }

    public function test_a_request_with_authorization_is_a_webhook_and_never_falls_back_to_the_browser_checks(): void
    {
        $this->postRaw((string) json_encode($this->payload()), ['HTTP_AUTHORIZATION' => 'Bearer wrong'])
            ->assertUnauthorized()
            ->assertExactJson(['status' => 'unauthorized']);

        $this->assertSame(0, Lead::query()->count());
        Http::assertNothingSent();
    }

    public function test_outside_local_and_testing_a_form_without_a_turnstile_secret_answers_503(): void
    {
        $this->app['env'] = 'production';
        $this->form->forceFill(['turnstile_secret' => null])->save();

        $this->post_($this->payload())->assertServiceUnavailable()->assertExactJson(['status' => 'unavailable'])->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_post_without_a_turnstile_token_is_422_and_stores_nothing(): void
    {
        $payload = $this->payload();
        unset($payload['cf-turnstile-response']);

        $this->post_($payload)->assertUnprocessable()->assertJsonPath('errors', ['cf-turnstile-response']);

        $this->assertSame(0, Lead::query()->count());
        Http::assertNothingSent();
    }

    public function test_local_and_testing_may_post_to_a_form_without_a_turnstile_secret(): void
    {
        $this->form->forceFill(['turnstile_secret' => null])->save();

        $this->post_($this->payload())->assertCreated();

        Http::assertNothingSent();
    }

    /** @param  array<string, mixed>|Closure  $answer */
    #[DataProvider('refusedSiteverify')]
    public function test_a_turnstile_answer_that_does_not_match_the_form_is_422(array|Closure $answer): void
    {
        $this->siteverify = $answer;

        $this->post_($this->payload())->assertUnprocessable()->assertJsonPath('errors', ['cf-turnstile-response']);

        $this->assertSame(0, Lead::query()->count());
        $this->assertSame(0, LeadIntake::query()->count());
    }

    public function test_the_per_ip_limit_of_a_form_answers_429(): void
    {
        config(['intake.per_minute' => 2]);

        $this->postRaw('[]')->assertUnprocessable();
        $this->postRaw('[]')->assertUnprocessable();
        $this->post_($this->payload())->assertTooManyRequests();

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_webhook_with_the_right_secret_is_held_to_the_forms_webhook_limit_not_the_per_ip_one(): void
    {
        config(['intake.per_minute' => 1, 'intake.webhook_per_minute' => 3]);
        $this->form->forceFill(['webhook_secret_hash' => hash('sha256', 'right')])->save();
        $webhook = fn () => $this->postRaw((string) json_encode(['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hi']), ['HTTP_AUTHORIZATION' => 'Bearer right']);

        foreach (range(1, 3) as $ignored) {
            $webhook()->assertCreated();
        }
        $webhook()->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame(3, Lead::query()->count());
    }

    public function test_one_forms_per_ip_limit_leaves_another_form_alone(): void
    {
        config(['intake.per_minute' => 1]);
        $second = $this->makeForm(Account::query()->findOrFail($this->form->account_id));

        $this->post_($this->payload())->assertCreated();
        $this->post_([...$this->payload(), 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2'])->assertTooManyRequests();

        $this->post_($this->payload(), $second->public_key)->assertCreated();
    }

    public function test_every_post_counts_against_the_ip_budget_refused_ones_too(): void
    {
        config(['intake.ip_per_minute' => 2]);

        $this->postRaw('name=Ewa', ['CONTENT_TYPE' => 'text/plain'])->assertStatus(415);
        $this->post_($this->payload(), str_repeat('0', 32))->assertNotFound();
        $this->post_($this->payload())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_accepted_posts_leave_the_refusal_budget_alone(): void
    {
        config(['intake.ip_per_minute' => 1]);

        $this->post_($this->payload())->assertCreated();
        $this->post_([...$this->payload(), 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2'])->assertCreated();
        $this->post_($this->payload())->assertOk();
    }

    public function test_two_visitors_behind_a_trusted_proxy_get_two_budgets(): void
    {
        config(['intake.ip_per_minute' => 1, 'trustedproxy.proxies' => '10.0.0.0/8']);
        $via = fn (string $visitor): array => ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => $visitor];

        $this->post_($this->payload(), str_repeat('0', 32), $via('203.0.113.7'))->assertNotFound();
        $this->post_($this->payload(), str_repeat('0', 32), $via('203.0.113.7'))->assertTooManyRequests();

        $this->post_($this->payload(), str_repeat('0', 32), $via('203.0.113.8'))->assertNotFound();
    }

    public function test_the_refusal_budget_runs_right_after_trust_proxies_and_before_maintenance(): void
    {
        $stack = app(\Illuminate\Contracts\Http\Kernel::class)->getGlobalMiddleware();
        $at = fn (string $class): int|false => array_search($class, $stack, true);

        $this->assertSame($at(\App\Http\Middleware\TrustProxies::class) + 1, $at(\App\Http\Middleware\ThrottleRefusedIntake::class));
        $this->assertSame($at(\App\Http\Middleware\ThrottleRefusedIntake::class) + 1, $at(GuardIntakeRequests::class));
        $this->assertLessThan($at(\Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class), $at(GuardIntakeRequests::class));
    }

    public function test_a_body_one_byte_over_the_cap_is_413_and_one_at_the_cap_is_not(): void
    {
        $max = (int) config('intake.max_bytes');
        $sized = fn (int $bytes): string => (string) json_encode([...$this->payload(), 'message' => str_repeat('a', $bytes - mb_strlen((string) json_encode([...$this->payload(), 'message' => '']), '8bit'))]);

        $this->assertSame($max + 1, mb_strlen($sized($max + 1), '8bit'));
        $this->postRaw($sized($max + 1))->assertStatus(413);
        $this->assertNotSame(413, $this->postRaw($sized($max))->getStatusCode());
    }

    public function test_an_ipv6_visitor_is_counted_by_its_64_prefix(): void
    {
        config(['intake.ip_per_minute' => 1]);

        $this->post_($this->payload(), str_repeat('0', 32), ['REMOTE_ADDR' => '2001:db8:1:2::1'])->assertNotFound();
        $this->post_($this->payload(), str_repeat('0', 32), ['REMOTE_ADDR' => '2001:db8:1:2::ffff'])->assertTooManyRequests();
        $this->post_($this->payload(), str_repeat('0', 32), ['REMOTE_ADDR' => '2001:db8:1:3::1'])->assertNotFound();
    }

    public function test_the_daily_quota_counts_only_new_leads_and_never_blocks_a_replay(): void
    {
        config(['intake.new_leads_per_day' => 1]);

        $this->postRaw('[]')->assertUnprocessable();
        $this->post_([...$this->payload(), 'website' => 'bot'])->assertCreated();
        $this->post_($this->payload())->assertCreated();

        $this->post_($this->payload())->assertOk();
        $this->post_([...$this->payload(), 'message' => 'Changed.'])->assertConflict();
        $this->post_([...$this->payload(), 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);

        $this->assertSame(1, Lead::query()->count());
    }

    public function test_a_key_in_another_case_is_not_found(): void
    {
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $this->form->public_key);

        $lookups = 0;
        DB::listen(function (QueryExecuted $query) use (&$lookups): void {
            $lookups += (int) str_contains($query->sql, 'from "intake_forms"');
        });

        $this->post_($this->payload(), mb_strtoupper($this->form->public_key))->assertNotFound();

        $this->assertSame(0, $lookups, 'a key in another case never reaches a case-insensitive column');
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_failing_form_lookup_answers_a_json_500_without_reflecting_an_unchecked_origin(): void
    {
        Schema::drop('lead_intakes');
        Schema::drop('intake_forms');
        Log::spy();

        $this->post_($this->payload())
            ->assertStatus(500)
            ->assertExactJson(['status' => 'error'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => array_keys($context) === ['exception', 'sqlstate']);
    }

    public function test_a_lost_race_on_the_replay_key_answers_the_replay_with_one_lead(): void
    {
        $fields = ['name' => 'Ewa Example', 'email' => 'ewa@example.test', 'message' => 'We need a new site.', 'company' => 'Example Studio', 'lang' => 'en', 'page_url' => 'https://www.example.test/kontakt', 'extras' => []];
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced, $fields): void {
            if ($raced || ! str_contains($query->sql, 'from "lead_intakes"')) {
                return;
            }
            static $seen = 0;
            if (++$seen < 2) {
                return;
            }
            $raced = true;
            $lead = Lead::create(['account_id' => $this->form->account_id, 'pipeline' => 'filipchrapek', 'source' => 'www-form', 'name' => 'Ewa Example', 'email' => 'ewa@example.test', 'external_ref' => "form-{$this->form->id}-".self::KEY]);
            LeadIntake::create(['account_id' => $this->form->account_id, 'intake_form_id' => $this->form->id, 'lead_id' => $lead->id, 'idempotency_key' => self::KEY, 'fingerprint' => FormIntake::fingerprint($fields)]);
        });

        $this->post_($this->payload())->assertOk()->assertExactJson(['status' => 'received']);

        $this->assertTrue($raced);
        $this->assertSame(1, Lead::query()->count());
        $this->assertSame(1, LeadIntake::query()->count());
    }

    public function test_a_failed_write_rolls_the_lead_back_and_logs_no_visitor_field(): void
    {
        DB::statement("CREATE TRIGGER refuse_intake BEFORE INSERT ON lead_intakes BEGIN SELECT RAISE(ABORT, 'refused'); END");
        Log::spy();

        $this->post_($this->payload())->assertStatus(500)->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);

        $this->assertSame(0, Lead::withTrashed()->count());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => array_keys($context) === ['form', 'exception', 'sqlstate']
            && ! preg_match('/ewa|Example Studio|new site/i', (string) json_encode($context)));
    }

    public function test_vary_is_added_to_what_the_response_already_varies_on(): void
    {
        $request = Request::create("/intake/{$this->form->public_key}", 'POST', server: ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'application/json'], content: '{}');

        $response = (new GuardIntakeRequests)->handle($request, fn (): Response => new Response('', 200, ['Vary' => 'Accept-Encoding']));

        $this->assertSame(['Accept-Encoding', 'Origin'], $response->headers->all('vary'));
    }

    public function test_the_demo_answers_404(): void
    {
        config(['app.demo' => true]);

        $this->post_($this->payload())->assertNotFound();
        $this->call('OPTIONS', "/intake/{$this->form->public_key}", server: ['HTTP_ORIGIN' => self::ORIGIN])->assertNotFound();

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_a_database_failure_answers_500_and_logs_the_class_and_sqlstate_only(): void
    {
        Schema::drop('lead_intakes');
        Log::spy();

        $response = $this->post_($this->payload());

        $response->assertStatus(500)->assertExactJson(['status' => 'error']);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => array_keys($context) === ['form', 'exception', 'sqlstate']
            && ! str_contains((string) json_encode($context), 'ewa@example.test'));
    }

    public function test_a_get_from_an_allowed_origin_is_405(): void
    {
        $this->call('GET', "/intake/{$this->form->public_key}", server: ['HTTP_ORIGIN' => self::ORIGIN])->assertMethodNotAllowed();
    }

    /** @return array<string, mixed> */
    private static function validPayload(): array
    {
        return [
            'name' => 'Ewa Example',
            'email' => 'Ewa@Example.test',
            'company' => 'Example Studio',
            'message' => 'We need a new site.',
            'idempotency_key' => self::KEY,
            'lang' => 'en',
            'page_url' => 'https://www.example.test/kontakt',
            'cf-turnstile-response' => 'token-from-widget',
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return self::validPayload();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $server
     */
    private function post_(array $payload, ?string $key = null, array $server = []): TestResponse
    {
        return $this->postRaw((string) json_encode($payload), $server, $key);
    }

    /** @param  array<string, string|null>  $server */
    private function postRaw(string $body, array $server = [], ?string $key = null): TestResponse
    {
        $server = array_filter([
            'HTTP_ORIGIN' => self::ORIGIN,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.7',
            ...$server,
        ], fn (?string $value): bool => $value !== null);

        return $this->call('POST', '/intake/'.($key ?? $this->form->public_key), server: $server, content: $body);
    }

    private function makeForm(Account $account): IntakeForm
    {
        return IntakeForm::create([
            'account_id' => $account->id,
            'name' => 'Contact drawer',
            'public_key' => IntakeForm::newPublicKey(),
            'allowed_origins' => [self::ORIGIN, 'http://localhost:4321'],
            'pipeline' => 'filipchrapek',
            'source' => 'www-form',
            'notify_to' => 'hi@example.test',
            'turnstile_secret' => 'form-turnstile-secret',
            'is_active' => true,
        ]);
    }

    private function fakeSiteverify(): void
    {
        Http::preventStrayRequests();
        Http::fake(['challenges.cloudflare.com/*' => fn () => $this->siteverify instanceof Closure ? ($this->siteverify)() : Http::response($this->siteverify)]);
    }
}

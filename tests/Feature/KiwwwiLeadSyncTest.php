<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Lead;
use App\Models\LeadStageEvent;
use App\Models\Setting;
use App\Models\StagedLeadSubmission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * kiwwwi:sync-leads — the scheduled pull that turns FluentForm submissions
 * from the brand sites (via the read-only mu-plugin endpoint on each) into
 * kiwwwi-pipeline leads. The contract under test: field mapping per form,
 * source derivation from tracking params, external_ref dedupe (including
 * soft-deleted rows), per-site ref namespaces and cursors, and tolerance of
 * malformed payloads and of one site being down.
 */
final class KiwwwiLeadSyncTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    /** @var array<int, mixed>|null */
    private ?array $endpointSubmissions = null;

    /** @var array<int, mixed>|null */
    private ?array $enSubmissions = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);

        Http::preventStrayRequests();

        config()->set('services.kiwwwi.leads', [
            'username' => 'leadsync',
            'app_password' => 'test-app-password',
            'endpoints' => [
                'pl' => ['base_url' => 'https://kiwwwi.pl', 'site' => 1, 'ref_prefix' => 'ff-'],
                // Not wired by default: the tests that need it set its URL.
                'en' => ['base_url' => null, 'site' => 2, 'ref_prefix' => 'ff-en-'],
            ],
        ]);
    }

    public function test_consent_mode_ads_click_without_gclid_maps_to_ads(): void
    {
        // Consent Mode denied-by-default: the ad landing carries gbraid/gad_*
        // and no gclid (mirrors real submission #132 from campaign 24045017187).
        $submission = $this->form3Submission();
        $submission['id'] = 9003;
        $submission['source_url'] = 'https://kiwwwi.pl/sklep-woocommerce/?gad_source=1&gad_campaignid=24045017187&gbraid=0AAAAAtest';
        $submission['utm'] = ['gad_source' => '1', 'gad_campaignid' => '24045017187', 'gbraid' => '0AAAAAtest'];

        $this->fakeEndpoint([$submission]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $lead = Lead::where('external_ref', 'ff-9003')->firstOrFail();
        $this->assertSame('ads', $lead->source);
    }

    public function test_happy_path_maps_both_forms_into_kiwwwi_leads(): void
    {
        $this->fakeEndpoint([$this->form3Submission(), $this->form6Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(2, Lead::count());

        // Form 3: free-text name, no tracking → the website-form channel.
        $form3 = Lead::where('external_ref', 'ff-9001')->firstOrFail();
        $this->assertSame('kiwwwi', $form3->pipeline);
        $this->assertSame('Jan Kowalski', $form3->name);
        $this->assertSame('jan@example.com', $form3->email);
        $this->assertSame('600100200', $form3->phone);
        $this->assertNull($form3->company);
        $this->assertSame('www-form', $form3->source);
        $this->assertSame('new', $form3->stage, 'entry stage comes from config, never visitor');
        $this->assertSame('2026-07-20 11:57:33', $form3->captured_at->utc()->format('Y-m-d H:i:s'), 'captured_at is the WP timestamp, not sync time');
        $this->assertStringContainsString('Site: kiwwwi.pl', (string) $form3->notes, 'the site label defaults to the host');

        // Form 6: nested name + company, gclid/cpc → the paid ads channel.
        $form6 = Lead::where('external_ref', 'ff-9002')->firstOrFail();
        $this->assertSame('Anna Nowak', $form6->name);
        $this->assertSame('Testowa Sp. z o.o.', $form6->company);
        $this->assertSame('ads', $form6->source);
        $this->assertStringContainsString('Prosze o wycene nowej strony.', (string) $form6->notes);
        // No consent record on the fixture = unknown: the click id is not
        // kept, the ads attribution is (KiwwwiLeadConsentTest covers the rest).
        $this->assertStringNotContainsString('TEST-GCLID', (string) $form6->notes);
        $this->assertStringContainsString('utm_medium=cpc', (string) $form6->notes);

        // Capture writes the null → entry_stage creation event (not a hop).
        $event = LeadStageEvent::where('lead_id', $form6->id)->sole();
        $this->assertNull($event->from_stage);
        $this->assertSame('new', $event->to_stage);

        // The request authenticated with the configured application password.
        Http::assertSent(function ($request): bool {
            return str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Basic ')
                && str_contains($request->url(), '/wp-json/kiwwwi/v1/lead-submissions');
        });
    }

    public function test_rerun_dedupes_on_external_ref_and_sends_since_from_last_pull(): void
    {
        $this->fakeEndpoint([$this->form3Submission(), $this->form6Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();
        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(2, Lead::count(), 're-run must not duplicate');
        $this->assertSame(1, Lead::where('external_ref', 'ff-9001')->count());

        // Second run resumes from the newest pulled captured_at (12:10 UTC).
        $withSince = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'since='))
            ->map(fn ($pair) => $pair[0]->data()['since'] ?? null);
        $this->assertContains('2026-07-20T12:10:00Z', $withSince, 'second pull must resume from the last captured_at');
    }

    public function test_a_lead_deleted_in_the_crm_stays_deleted_on_rerun(): void
    {
        $this->fakeEndpoint([$this->form3Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();
        Lead::where('external_ref', 'ff-9001')->firstOrFail()->delete();

        $this->artisan('kiwwwi:sync-leads --full')->assertSuccessful();

        $this->assertSame(0, Lead::count(), 'soft-deleted lead must not resurrect');
        $this->assertSame(1, Lead::withTrashed()->count());
    }

    public function test_malformed_submissions_are_skipped_without_aborting_the_pull(): void
    {
        $this->fakeEndpoint([
            ['nonsense' => true],                                   // no id
            'not-even-an-object',                                   // wrong type
            ['id' => 9004, 'response' => 'garbage-not-an-array'],    // rotten fields
            $this->form3Submission(),                               // still lands
        ]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(1, Lead::whereNotNull('email')->count(), 'the valid submission still lands');
        $this->assertSame('ff-9001', Lead::where('email', 'jan@example.com')->sole()->external_ref);

        // The id-bearing rotten row still creates a minimal lead (fallback
        // name, www-form source) rather than being lost — only id-less noise
        // is uncreatable.
        $minimal = Lead::where('external_ref', 'ff-9004')->first();
        $this->assertNotNull($minimal);
        $this->assertSame('FluentForm entry #9004', $minimal->name);
    }

    public function test_unexpected_payload_shape_fails_the_command_cleanly(): void
    {
        Http::fake([
            'kiwwwi.pl/*' => Http::response(['unexpected' => 'shape']),
        ]);

        $this->artisan('kiwwwi:sync-leads')->assertFailed();
        $this->assertSame(0, Lead::count());
    }

    public function test_missing_credentials_fail_the_command_cleanly(): void
    {
        config()->set('services.kiwwwi.leads.app_password', null);
        Http::fake();

        $this->artisan('kiwwwi:sync-leads')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_a_submission_that_fails_is_kept_staged_and_imported_on_a_later_run(): void
    {
        Log::spy();
        $broken = true;
        Lead::creating(function (Lead $lead) use (&$broken): void {
            if ($broken && $lead->external_ref === 'ff-9001') {
                throw new RuntimeException('Mapping drift');
            }
        });
        $this->fakeEndpoint([$this->form6Submission(), $this->form3Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertNull(Lead::where('external_ref', 'ff-9001')->first());
        $this->assertNotNull(Lead::where('external_ref', 'ff-9002')->first(), 'the newer lead lands');
        $staged = StagedLeadSubmission::where('external_ref', 'ff-9001')->sole();
        $this->assertSame(1, $staged->attempts);
        $this->assertStringContainsString('Mapping drift', (string) $staged->error);
        $this->assertSame(9001, $staged->payload['id']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []): bool => ($context['external_ref'] ?? null) === 'ff-9001'
                && str_contains((string) ($context['error'] ?? ''), 'RuntimeException: Mapping drift')
        );

        // The cursor is past it now; the staged copy is what gets retried.
        $broken = false;
        $this->fakeEndpoint([]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $lead = Lead::where('external_ref', 'ff-9001')->sole();
        $this->assertSame('Jan Kowalski', $lead->name);
        $this->assertSame('www-form', $lead->source);
        $event = LeadStageEvent::where('lead_id', $lead->id)->sole();
        $this->assertNull($event->from_stage);
        $this->assertSame('new', $event->to_stage);
        $this->assertSame(0, StagedLeadSubmission::count());
        $this->assertSame(2, Lead::count());
    }

    public function test_an_older_submission_whose_failure_cannot_be_recorded_is_not_lost_behind_a_newer_one(): void
    {
        // Id 1 captured at 12:00, id 2 at 11:00: id order and time order disagree.
        $newer = [...$this->form3Submission(), 'id' => 1, 'created_at_utc' => '2026-07-20T12:00:00Z'];
        $older = [...$this->form6Submission(), 'id' => 2, 'created_at_utc' => '2026-07-20T11:00:00Z'];
        $broken = true;
        Lead::creating(function (Lead $lead) use (&$broken): void {
            if ($broken && $lead->external_ref === 'ff-2') {
                throw new RuntimeException('Import fails');
            }
        });
        StagedLeadSubmission::updating(function () use (&$broken): void {
            if ($broken) {
                throw new RuntimeException('Recording the failure fails too');
            }
        });
        $this->fakeEndpoint([$newer, $older]);

        $this->artisan('kiwwwi:sync-leads')->assertFailed();

        $this->assertNotNull(Lead::where('external_ref', 'ff-1')->first());
        $this->assertNull(Lead::where('external_ref', 'ff-2')->first());
        $this->assertNotNull(StagedLeadSubmission::where('external_ref', 'ff-2')->first(), 'still staged');

        $broken = false;
        $this->fakeEndpoint([]);
        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(1, Lead::where('external_ref', 'ff-2')->count());
        $this->assertSame(0, StagedLeadSubmission::count());
        $this->assertContains('2026-07-20T12:00:00Z', $this->sentSinceValues(), 'the cursor reached the newest staged submission');
    }

    public function test_a_run_that_dies_after_staging_loses_nothing(): void
    {
        // Every import throws and so does every failure write: the run dies
        // with the batch staged and nothing imported.
        $dead = true;
        Lead::creating(function () use (&$dead): void {
            if ($dead) {
                throw new RuntimeException('Process gone');
            }
        });
        StagedLeadSubmission::updating(function () use (&$dead): void {
            if ($dead) {
                throw new RuntimeException('Process gone');
            }
        });
        $this->fakeEndpoint([$this->form3Submission(), $this->form6Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertFailed();

        $this->assertSame(0, Lead::count());
        $this->assertSame(['ff-9001', 'ff-9002'], StagedLeadSubmission::orderBy('external_ref')->pluck('external_ref')->all());

        $dead = false;
        $this->fakeEndpoint([]);
        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(2, Lead::count());
        $this->assertSame(0, StagedLeadSubmission::count());
    }

    public function test_a_failed_pull_still_imports_what_is_staged(): void
    {
        StagedLeadSubmission::create([
            'account_id' => $this->account->id,
            'external_ref' => 'ff-9001',
            'payload' => $this->form3Submission(),
        ]);
        Http::fake(['kiwwwi.pl/*' => Http::response('Service Unavailable', 503)]);

        $this->artisan('kiwwwi:sync-leads')->assertFailed();

        $this->assertSame(1, Lead::where('external_ref', 'ff-9001')->count());
        $this->assertSame(0, StagedLeadSubmission::count());
    }

    public function test_a_second_run_does_nothing_while_one_is_in_progress(): void
    {
        $this->fakeEndpoint([$this->form3Submission()]);
        $held = Cache::lock('kiwwwi:sync-leads:'.$this->account->id, 600);
        $this->assertTrue($held->get());

        $this->artisan('kiwwwi:sync-leads')
            ->expectsOutputToContain('in progress')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, StagedLeadSubmission::count());

        $held->release();
        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();
        $this->assertSame(1, Lead::count());
    }

    public function test_the_scheduled_pull_does_not_overlap_itself(): void
    {
        config()->set('services.kiwwwi.leads.app_password', 'set');

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'kiwwwi:sync-leads'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_a_submission_that_keeps_failing_is_given_up_listed_and_requeued_on_request(): void
    {
        Log::spy();
        $broken = true;
        Lead::creating(function (Lead $lead) use (&$broken): void {
            if ($broken && $lead->external_ref === 'ff-9001') {
                throw new RuntimeException('Still broken');
            }
        });
        $this->fakeEndpoint([$this->form3Submission()]);

        for ($run = 1; $run <= StagedLeadSubmission::MAX_ATTEMPTS; $run++) {
            $this->artisan('kiwwwi:sync-leads')->assertSuccessful();
        }

        $staged = StagedLeadSubmission::where('external_ref', 'ff-9001')->sole();
        $this->assertSame(StagedLeadSubmission::MAX_ATTEMPTS, $staged->attempts);
        $this->assertNotNull($staged->given_up_at);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context = []): bool => ($context['external_ref'] ?? null) === 'ff-9001'
                && str_contains($message, 'no longer retried')
        )->once();

        // Given up: listed on every run, no longer attempted.
        $this->artisan('kiwwwi:sync-leads')
            ->expectsOutputToContain('ff-9001 after 5 attempts')
            ->assertSuccessful();
        $this->assertSame(StagedLeadSubmission::MAX_ATTEMPTS, $staged->fresh()->attempts);

        $broken = false;
        $this->artisan('kiwwwi:sync-leads --retry-failed')->assertSuccessful();

        $this->assertSame(1, Lead::where('external_ref', 'ff-9001')->count());
        $this->assertSame(0, StagedLeadSubmission::count());
    }

    public function test_a_staged_submission_whose_lead_was_imported_and_deleted_is_not_recreated(): void
    {
        $this->fakeEndpoint([$this->form3Submission()]);
        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();
        Lead::where('external_ref', 'ff-9001')->firstOrFail()->delete();

        StagedLeadSubmission::create([
            'account_id' => $this->account->id,
            'external_ref' => 'ff-9001',
            'payload' => $this->form3Submission(),
            'error' => 'RuntimeException: earlier',
            'attempts' => 1,
        ]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Lead::withTrashed()->count());
        $this->assertSame(0, StagedLeadSubmission::count());
    }

    public function test_an_http_error_is_logged_with_status_and_endpoint(): void
    {
        Log::spy();
        Http::fake(['kiwwwi.pl/*' => Http::response('Service Unavailable', 503)]);

        $this->artisan('kiwwwi:sync-leads')
            ->expectsOutputToContain('HTTP 503')
            ->assertFailed();

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'HTTP 503')
                && $context['status'] === 503
                && $context['endpoint'] === '/wp-json/kiwwwi/v1/lead-submissions'
                && ! str_contains(json_encode($context).$message, 'test-app-password')
        )->once();
    }

    public function test_a_connection_error_is_logged_without_credentials_or_query_string(): void
    {
        Log::spy();
        Http::fake(function (): never {
            // Built in pieces so the fixture itself carries no credential-shaped URL.
            $userinfo = 'leadsync:test-app-password';
            $url = "https://{$userinfo}@leads.example.test/wp-json/kiwwwi/v1/lead-submissions?since=2026-07-20T12:10:00Z";

            throw new ConnectionException('cURL error 7: Failed to connect for '.$url);
        });

        $this->artisan('kiwwwi:sync-leads')->assertFailed();

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $logged = $message.json_encode($context);

            return str_contains($message, 'cURL error 7')
                && str_contains($message, 'leads.example.test/wp-json/kiwwwi/v1/lead-submissions')
                && $context['exception'] === ConnectionException::class
                && ! str_contains($logged, 'test-app-password')
                && ! str_contains($logged, 'since=');
        })->once();
    }

    public function test_missing_configuration_is_logged(): void
    {
        Log::spy();
        config()->set('services.kiwwwi.leads.app_password', null);

        $this->artisan('kiwwwi:sync-leads')->assertFailed();

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message): bool => str_contains($message, 'not configured')
        )->once();
    }

    public function test_both_sites_are_polled_and_each_lead_names_its_site(): void
    {
        $this->wireEnSite();
        $this->fakeEndpoint([$this->form3Submission()]);
        $this->fakeEnEndpoint([$this->enForm3Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(2, Lead::count());
        $en = Lead::where('external_ref', 'ff-en-55')->firstOrFail();
        $this->assertSame('kiwwwi', $en->pipeline, 'one brand pipeline, two language sites');
        $this->assertSame('John Smith', $en->name);
        $this->assertSame('www-form', $en->source, 'source stays the channel, never the language');
        $this->assertStringContainsString('Site: kiwwwi.studio', (string) $en->notes);
        $this->assertSame('2026-08-08 12:30:00', $en->captured_at->utc()->format('Y-m-d H:i:s'));

        foreach (['kiwwwi.pl', 'kiwwwi.studio'] as $host) {
            Http::assertSent(fn ($request): bool => str_contains($request->url(), $host.'/wp-json/kiwwwi/v1/lead-submissions')
                && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Basic '));
        }
    }

    public function test_the_same_submission_id_on_both_sites_makes_two_leads(): void
    {
        $this->wireEnSite();
        $this->fakeEndpoint([$this->form3Submission()]);
        $this->fakeEnEndpoint([[...$this->enForm3Submission(), 'id' => 9001]]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame('jan@example.com', Lead::where('external_ref', 'ff-9001')->sole()->email);
        $this->assertSame('john@example.test', Lead::where('external_ref', 'ff-en-9001')->sole()->email);
    }

    public function test_one_site_failing_does_not_block_the_other(): void
    {
        $this->wireEnSite();
        Http::fake(['kiwwwi.pl/*' => Http::response('upstream exploded', 500)]);
        $this->fakeEnEndpoint([$this->enForm3Submission()]);

        $this->artisan('kiwwwi:sync-leads')
            ->expectsOutputToContain('[pl]')
            ->assertFailed();

        $this->assertNotNull(Lead::where('external_ref', 'ff-en-55')->first(), 'the healthy site still lands');
        $this->assertSame(1, Lead::count());
    }

    public function test_each_site_resumes_from_its_own_newest_submission(): void
    {
        $this->wireEnSite();
        $this->fakeEndpoint([$this->form3Submission(), $this->form6Submission()]);
        $this->fakeEnEndpoint([$this->enForm3Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();
        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(3, Lead::count(), 're-run must not duplicate');
        $sinceByHost = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'since='))
            ->mapWithKeys(fn ($pair) => [parse_url($pair[0]->url(), PHP_URL_HOST) => $pair[0]->data()['since'] ?? null]);

        // The EN lead is newer than every PL lead; a shared cursor would skip PL submissions.
        $this->assertSame('2026-07-20T12:10:00Z', $sinceByHost['kiwwwi.pl'] ?? null);
        $this->assertSame('2026-08-08T12:30:00Z', $sinceByHost['kiwwwi.studio'] ?? null);
    }

    public function test_the_single_site_cursor_carries_over_to_the_first_site(): void
    {
        Setting::create([
            'account_id' => $this->account->id,
            'scope' => 'kiwwwi_lead_sync',
            'data' => ['cursor' => '2026-07-19T08:00:00+00:00'],
        ]);
        $this->fakeEndpoint([]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(['2026-07-19T08:00:00Z'], $this->sentSinceValues());
    }

    public function test_a_site_url_answering_as_another_blog_is_refused(): void
    {
        $this->wireEnSite();
        $this->fakeEndpoint([$this->form3Submission()]);
        Http::fake(['kiwwwi.studio/*' => Http::response([
            'site' => 1,
            'site_time_zone' => 'Europe/Warsaw',
            'submissions' => [$this->form3Submission()],
        ])]);

        $this->artisan('kiwwwi:sync-leads')->assertFailed();

        $this->assertSame(1, Lead::count(), 'only the correctly wired site imports');
        $this->assertSame(0, Lead::where('external_ref', 'like', 'ff-en-%')->count());
    }

    public function test_a_local_submission_time_is_read_in_the_site_time_zone(): void
    {
        $submission = $this->form3Submission();
        unset($submission['created_at_utc']);
        $this->fakeEndpoint([$submission]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $lead = Lead::where('external_ref', 'ff-9001')->sole();
        $this->assertSame('2026-07-20 11:57:33', $lead->captured_at->utc()->format('Y-m-d H:i:s'), '13:57 Warsaw is 11:57 UTC');
    }

    public function test_an_unwired_site_is_never_requested(): void
    {
        $this->fakeEndpoint([$this->form3Submission()]);

        $this->artisan('kiwwwi:sync-leads')->assertSuccessful();

        $this->assertSame(1, Lead::count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'kiwwwi.studio'));
    }

    private function wireEnSite(): void
    {
        config()->set('services.kiwwwi.leads.endpoints.en.base_url', 'https://kiwwwi.studio');
    }

    /**
     * @param  array<int, mixed>  $submissions
     */
    private function fakeEnEndpoint(array $submissions): void
    {
        $first = $this->enSubmissions === null;
        $this->enSubmissions = $submissions;

        if ($first) {
            Http::fake([
                'kiwwwi.studio/wp-json/kiwwwi/v1/lead-submissions*' => fn () => Http::response([
                    'site' => 2,
                    'site_time_zone' => 'Europe/Warsaw',
                    'count' => count($this->enSubmissions ?? []),
                    'submissions' => $this->enSubmissions ?? [],
                ]),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function enForm3Submission(): array
    {
        return [
            'id' => 55,
            'form_id' => 3,
            'status' => 'unread',
            'created_at' => '2026-08-08 14:30:00',
            'created_at_utc' => '2026-08-08T12:30:00Z',
            'source_url' => 'https://kiwwwi.studio/',
            'response' => [
                'input_text' => 'John Smith',
                'email' => 'john@example.test',
                'phone' => '600500600',
            ],
            'utm' => [],
        ];
    }

    /**
     * @param  array<int, mixed>  $submissions
     */
    private function fakeEndpoint(array $submissions): void
    {
        // Http::fake stubs stack and the first match wins, so one stub reads the current list.
        $first = $this->endpointSubmissions === null;
        $this->endpointSubmissions = $submissions;

        if ($first) {
            Http::fake([
                'kiwwwi.pl/wp-json/kiwwwi/v1/lead-submissions*' => fn () => Http::response([
                    'site' => 1,
                    'site_time_zone' => 'Europe/Warsaw',
                    'count' => count($this->endpointSubmissions ?? []),
                    'submissions' => $this->endpointSubmissions ?? [],
                ]),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function sentSinceValues(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['since'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function form3Submission(): array
    {
        return [
            'id' => 9001,
            'form_id' => 3,
            'status' => 'unread',
            'created_at' => '2026-07-20 13:57:33',
            'created_at_utc' => '2026-07-20T11:57:33Z',
            'source_url' => 'https://kiwwwi.pl/',
            'response' => [
                'input_text' => 'Jan Kowalski',
                'email' => 'jan@example.com',
                'phone' => '600100200',
            ],
            'utm' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function form6Submission(): array
    {
        return [
            'id' => 9002,
            'form_id' => 6,
            'status' => 'unread',
            'created_at' => '2026-07-20 14:10:00',
            'created_at_utc' => '2026-07-20T12:10:00Z',
            'source_url' => 'https://kiwwwi.pl/kontakt/?utm_source=google&utm_medium=cpc&gclid=TEST-GCLID',
            'response' => [
                'name' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
                'company_name' => 'Testowa Sp. z o.o.',
                'email' => 'anna@example.com',
                'phone' => '600300400',
                'message' => 'Prosze o wycene nowej strony.',
                'user_type' => 'Firma',
                'contact_preference' => 'email',
            ],
            'utm' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'TEST-GCLID'],
        ];
    }
}

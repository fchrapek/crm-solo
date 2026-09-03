<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Lead;
use App\Models\LeadStageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * kiwwwi:sync-leads — the scheduled pull that turns kiwwwi.pl FluentForm
 * submissions (via the read-only mu-plugin endpoint) into kiwwwi-pipeline
 * leads. The contract under test: field mapping per form, source derivation
 * from tracking params, external_ref dedupe (including soft-deleted rows),
 * and tolerance of malformed payloads.
 */
final class KiwwwiLeadSyncTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);

        config()->set('services.kiwwwi.leads', [
            'base_url' => 'https://kiwwwi.pl',
            'username' => 'leadsync',
            'app_password' => 'test-app-password',
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

        // Form 6: nested name + company, gclid/cpc → the paid ads channel.
        $form6 = Lead::where('external_ref', 'ff-9002')->firstOrFail();
        $this->assertSame('Anna Nowak', $form6->name);
        $this->assertSame('Testowa Sp. z o.o.', $form6->company);
        $this->assertSame('ads', $form6->source);
        $this->assertStringContainsString('Prosze o wycene nowej strony.', (string) $form6->notes);
        $this->assertStringContainsString('gclid=TEST-GCLID', (string) $form6->notes);

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

    /**
     * @param  array<int, mixed>  $submissions
     */
    private function fakeEndpoint(array $submissions): void
    {
        Http::fake([
            'kiwwwi.pl/wp-json/kiwwwi/v1/lead-submissions*' => Http::response([
                'site' => 1,
                'site_time_zone' => 'Europe/Warsaw',
                'count' => count($submissions),
                'submissions' => $submissions,
            ]),
        ]);
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\InfaktDraftRequest;
use App\Models\Integration;
use App\Services\Integrations\InfaktDraftRun;
use App\Services\Integrations\InfaktService;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class InfaktDraftInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Studio']);
    }

    /**
     * @return array<string, array{0: int, 1: ?string}>
     */
    public static function uncertainAnswers(): array
    {
        return [
            'gateway timeout' => [504, null],
            'server error' => [500, null],
            'service unavailable' => [503, null],
            'rate limited' => [429, null],
            'dropped connection' => [0, null],
            'accepted without a reference' => [202, ''],
        ];
    }

    public function test_builds_a_draft_maintenance_payload_with_month_end_sale_date(): void
    {
        $payload = $this->service()->buildMaintenanceInvoicePayload(
            $this->maintenanceClient('Utrzymanie strony i wsparcie techniczne'),
            Carbon::parse('2026-06-15'),
        )['invoice'];

        $this->assertSame(10000002, $payload['client_id']);
        $this->assertSame('2026-06-30', $payload['sale_date']); // last day of the billed month
        $this->assertSame('transfer', $payload['payment_method']);

        $line = $payload['services'][0];
        $this->assertSame('Utrzymanie strony i wsparcie techniczne', $line['name']);
        $this->assertSame(22000, $line['unit_net_price']); // 220,00 in grosze
        $this->assertSame('23', $line['tax_symbol']);
        $this->assertSame(1, $line['quantity']);
    }

    public function test_the_default_period_and_invoice_date_follow_the_local_calendar(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        // 00:30 on 1 November in Warsaw: the month just ended is October, the invoice is dated today locally.
        $this->travelTo(Carbon::parse('2026-10-31 23:30:00', 'UTC'));
        $this->service();
        $client = $this->maintenanceClient('Utrzymanie');

        $this->assertSame(0, Artisan::call('infakt:draft-invoice', ['client' => (string) $client->id, '--dry-run' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('(2026-10)', $output);
        $this->assertStringContainsString('"sale_date": "2026-10-31"', $output);
        $this->assertStringContainsString('"invoice_date": "2026-11-01"', $output);
    }

    public function test_falls_back_to_default_description(): void
    {
        $payload = $this->service()->buildMaintenanceInvoicePayload(
            $this->maintenanceClient(null),
            Carbon::parse('2026-06-15'),
        )['invoice'];

        $this->assertSame(InfaktService::DEFAULT_MAINTENANCE_DESCRIPTION, $payload['services'][0]['name']);
    }

    public function test_refuses_a_non_maintenance_client(): void
    {
        $client = $this->account->clients()->create([
            'name' => 'Gig Co',
            'month_close_type' => 'gig',
            'external_ids' => ['infakt' => '1'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->buildMaintenanceInvoicePayload($client, Carbon::parse('2026-06-15'));
    }

    public function test_refuses_a_client_without_an_active_retainer(): void
    {
        $client = $this->account->clients()->create([
            'name' => 'No Retainer',
            'month_close_type' => 'maintenance',
            'external_ids' => ['infakt' => '1'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->buildMaintenanceInvoicePayload($client, Carbon::parse('2026-06-15'));
    }

    public function test_refuses_a_client_without_a_linked_infakt_id(): void
    {
        $client = $this->maintenanceClient('x');
        $client->update(['external_ids' => []]);

        $this->expectException(RuntimeException::class);
        $this->service()->buildMaintenanceInvoicePayload($client->fresh(), Carbon::parse('2026-06-15'));
    }

    public function test_the_payload_carries_the_clients_currency(): void
    {
        $client = $this->maintenanceClient('x');
        $client->update(['currency' => 'EUR']);

        $payload = $this->service()->buildMaintenanceInvoicePayload($client->fresh(), Carbon::parse('2026-06-15'))['invoice'];

        $this->assertSame('EUR', $payload['currency']);
    }

    public function test_a_foreign_currency_draft_sends_the_exchange_date_kind(): void
    {
        Carbon::setTestNow('2026-07-02 09:00:00');
        $client = $this->maintenanceClient('Hosting and support');
        $client->update(['currency' => 'EUR']);
        $this->fakeInfakt(posts: [['ref-1', 202]], statuses: [201]);

        $this->draft($client->fresh())->assertSuccessful();

        $this->assertSame(['invoice' => [
            'client_id' => 10000002,
            'sale_date' => '2026-06-30',
            'invoice_date' => '2026-07-02',
            'payment_method' => 'transfer',
            'currency' => 'EUR',
            'vat_exchange_date_kind' => 'vat',
            'services' => [[
                'name' => 'Hosting and support',
                'unit_net_price' => 22000,
                'quantity' => 1,
                'tax_symbol' => '23',
                'unit' => 'usł.',
            ]],
        ]], $this->posts()[0][0]->data());
        Carbon::setTestNow();
    }

    public function test_the_exchange_date_kind_follows_config_and_is_left_out_for_pln(): void
    {
        config(['services.infakt.vat_exchange_date_kind' => 'pit']);
        $client = $this->maintenanceClient('x');
        $service = $this->service();

        $this->assertArrayNotHasKey('vat_exchange_date_kind', $service->buildMaintenanceInvoicePayload($client, Carbon::parse('2026-06-15'))['invoice']);

        $client->update(['currency' => 'EUR']);
        $this->assertSame('pit', $service->buildMaintenanceInvoicePayload($client->fresh(), Carbon::parse('2026-06-15'))['invoice']['vat_exchange_date_kind']);

        config(['services.infakt.vat_exchange_date_kind' => 'sale_date']);
        $this->expectException(RuntimeException::class);
        $service->buildMaintenanceInvoicePayload($client->fresh(), Carbon::parse('2026-06-15'));
    }

    public function test_running_the_command_twice_for_a_month_drafts_one_invoice(): void
    {
        $client = $this->maintenanceClient('x');
        $this->fakeInfakt(posts: [['ref-1', 202]], statuses: [201]);

        $this->draft($client)->assertSuccessful();
        $this->draft($client)->expectsOutputToContain('already drafted earlier, skipped')->assertSuccessful();

        $this->assertCount(1, $this->posts());
        $request = InfaktDraftRequest::sole();
        $this->assertSame(InfaktDraftRequest::STATUS_CREATED, $request->status);
        $this->assertSame('uuid-ref-1', $request->invoice_uuid);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains(urldecode($r->url()), 'q[client_id_eq]=10000002')
            && str_contains(urldecode($r->url()), 'q[sale_date_eq]=2026-06-30'));
    }

    public function test_a_retry_while_infakt_is_still_building_the_draft_sends_nothing_new(): void
    {
        $client = $this->maintenanceClient('x');
        // Accepted, then "processing" for every poll of the first run and the check of the second.
        $this->fakeInfakt(posts: [['ref-1', 202]], statuses: array_merge(array_fill(0, 9, 140), [201]));

        $this->draft($client)->expectsOutputToContain('Infakt still processing')->assertSuccessful();
        $this->assertSame(InfaktDraftRequest::STATUS_SUBMITTED, InfaktDraftRequest::sole()->status);

        $this->draft($client)->expectsOutputToContain('Infakt still processing')->assertSuccessful();
        $this->draft($client)->expectsOutputToContain('already drafted earlier, skipped')->assertSuccessful();

        $this->assertCount(1, $this->posts());
        $this->assertSame(InfaktDraftRequest::STATUS_CREATED, InfaktDraftRequest::sole()->status);
    }

    public function test_a_run_that_crashed_between_claim_and_send_blocks_the_group(): void
    {
        $client = $this->maintenanceClient('x');
        // Claimed, then the process died before the request went out.
        $this->row($client, InfaktDraftRequest::STATUS_SENDING, attempt: 1, lease: now()->addMinutes(InfaktDraftRun::LEASE_MINUTES));
        $this->fakeInfakt(posts: [], statuses: []);

        $this->draft($client)->expectsOutputToContain('not sent: another run holds it')->assertFailed();

        $this->travel(InfaktDraftRun::LEASE_MINUTES + 1)->minutes();
        $this->draft($client)->expectsOutputToContain('not sent: earlier request unconfirmed')->assertFailed();

        $this->assertCount(0, $this->posts());
        $this->assertSame(1, InfaktDraftRequest::sole()->attempt);
    }

    public function test_a_run_landing_while_another_sends_posts_nothing(): void
    {
        $client = $this->maintenanceClient('x');
        $nested = null;
        $this->fakeInfakt(posts: [], statuses: [201], onPost: function () use ($client, &$nested) {
            // The second run starts while the first one's request is on the wire.
            $nested = $this->service()->createDraftMaintenanceInvoices($client, Carbon::parse('2026-06-01'));

            return Http::response(['invoice_task_reference_number' => 'ref-1', 'processing_code' => 100], 202);
        });

        $this->draft($client)->assertSuccessful();

        $this->assertSame(InfaktDraftRun::BUSY, $nested[0]['outcome']);
        $this->assertCount(1, $this->posts());
    }

    public function test_two_concurrent_resend_runs_send_once(): void
    {
        $client = $this->maintenanceClient('x');
        $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1);
        $nested = null;
        $this->fakeInfakt(posts: [], statuses: [201], onPost: function () use ($client, &$nested) {
            $nested = $this->service()->createDraftMaintenanceInvoices($client, Carbon::parse('2026-06-01'), resendUnconfirmed: true);

            return Http::response(['invoice_task_reference_number' => 'ref-1', 'processing_code' => 100], 202);
        });

        $this->artisan('infakt:draft-invoice', ['client' => (string) $client->id, '--period' => '2026-06', '--resend-unconfirmed' => true])->assertSuccessful();

        $this->assertSame(InfaktDraftRun::BUSY, $nested[0]['outcome']);
        $this->assertCount(1, $this->posts());
        $this->assertSame(2, InfaktDraftRequest::sole()->attempt);
    }

    public function test_a_resend_from_a_stale_read_loses_to_the_attempt_that_ran_meanwhile(): void
    {
        $client = $this->maintenanceClient('Hosting');
        $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1, fingerprint: $this->sent('Hosting', 22000));
        $this->fakeInfakt(posts: [[null, 504], ['ref-late', 202]], statuses: [201]);

        // Run B reads the row (unconfirmed, attempt 1). Before it claims, run A resends and also gets no
        // answer, so the row is unconfirmed again, now on attempt 2: only the attempt number tells B its read is stale.
        $looked = false;
        $reads = 0;
        $nestedRan = false;
        Http::fake(['api.infakt.pl/v3/invoices.json*' => function () use (&$looked) {
            $looked = true;

            return Http::response(['entities' => []]);
        }]);
        DB::listen(function (QueryExecuted $query) use (&$looked, &$reads, &$nestedRan, $client): void {
            if ($nestedRan || ! $looked || ! str_starts_with(mb_strtolower($query->sql), 'select') || ! str_contains($query->sql, 'infakt_draft_requests')) {
                return;
            }
            if (++$reads === 2) {
                $nestedRan = true;
                try {
                    $this->service()->createDraftMaintenanceInvoices($client, Carbon::parse('2026-06-01'), resendUnconfirmed: true);
                } catch (RuntimeException) {
                    // Run A's 504: its attempt is recorded as unconfirmed.
                }
            }
        });

        $this->artisan('infakt:draft-invoice', ['client' => (string) $client->id, '--period' => '2026-06', '--resend-unconfirmed' => true])
            ->expectsOutputToContain('not sent: another run holds it')
            ->assertFailed();

        $this->assertTrue($nestedRan);
        $this->assertCount(1, $this->posts());
        $request = InfaktDraftRequest::sole();
        $this->assertSame(InfaktDraftRequest::STATUS_UNCONFIRMED, $request->status);
        $this->assertSame(2, $request->attempt);
    }

    public function test_a_late_rejection_for_an_older_attempt_leaves_the_newer_one_alone(): void
    {
        $client = $this->maintenanceClient('Hosting');
        $this->row($client, InfaktDraftRequest::STATUS_SUBMITTED, attempt: 1, reference: 'ref-same', fingerprint: $this->sent('Hosting', 22000));

        // Poller P asks about attempt 1. While its answer (a rejection) is on the way, run Q gets the same
        // rejection, resends as attempt 2 and is accepted; its task carries the same reference here, so only
        // the attempt number tells P's late answer from the current one.
        $calls = 0;
        $this->fakeInfakt(posts: [['ref-same', 202]], statuses: [], onStatus: function () use (&$calls, $client) {
            $calls++;
            if ($calls === 1) {
                $this->service()->createDraftMaintenanceInvoices($client, Carbon::parse('2026-06-01'));

                return Http::response(['processing_code' => 422, 'processing_description' => 'Nie udało się stworzyć faktury']);
            }

            return $calls === 2
                ? Http::response(['processing_code' => 422, 'processing_description' => 'Nie udało się stworzyć faktury'])
                : Http::response(['processing_code' => 140, 'processing_description' => 'Zlecenie jest w trakcie przetwarzania']);
        });

        try {
            $this->service()->createDraftMaintenanceInvoices($client, Carbon::parse('2026-06-01'));
        } catch (RuntimeException) {
            // Not expected; the assertions below say what happened.
        }

        $request = InfaktDraftRequest::sole();
        $this->assertSame(2, $request->attempt);
        $this->assertSame(InfaktDraftRequest::STATUS_SUBMITTED, $request->status);

        // The next run asks again and sends nothing new.
        $this->draft($client)->expectsOutputToContain('Infakt still processing')->assertSuccessful();
        $this->assertCount(1, $this->posts());
    }

    public function test_an_unconfirmed_request_is_resent_only_when_asked_as_one_new_attempt(): void
    {
        $client = $this->maintenanceClient('x');
        $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1);
        $this->fakeInfakt(posts: [['ref-2', 202]], statuses: [201]);

        $this->draft($client)->expectsOutputToContain('not sent: earlier request unconfirmed')->assertFailed();
        $this->assertCount(0, $this->posts());

        $this->artisan('infakt:draft-invoice', ['client' => (string) $client->id, '--period' => '2026-06', '--resend-unconfirmed' => true])
            ->assertSuccessful();

        $this->assertCount(1, $this->posts());
        $request = InfaktDraftRequest::sole();
        $this->assertSame(InfaktDraftRequest::STATUS_CREATED, $request->status);
        $this->assertSame(2, $request->attempt);
        $this->assertSame('ref-2', $request->task_reference);
    }

    public function test_a_lost_draft_found_in_infakt_is_recorded_without_sending(): void
    {
        $client = $this->maintenanceClient('Hosting');
        $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1, fingerprint: $this->sent('Hosting', 22000));
        $this->fakeInfakt(posts: [], statuses: [], lookup: [
            $this->listed(20000200, 'uuid-found', '6/2026', [['Hosting', 22000]]),
        ]);

        $this->draft($client)->expectsOutputToContain('already drafted earlier, skipped')->assertSuccessful();

        $this->assertCount(0, $this->posts());
        $request = InfaktDraftRequest::sole();
        $this->assertSame(InfaktDraftRequest::STATUS_CREATED, $request->status);
        $this->assertSame('uuid-found', $request->invoice_uuid);
    }

    public function test_two_invoices_with_the_sent_lines_leave_a_lost_draft_unconfirmed_and_named(): void
    {
        $client = $this->maintenanceClient('Hosting');
        $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1, fingerprint: $this->sent('Hosting', 22000));
        $this->fakeInfakt(posts: [], statuses: [], lookup: [
            $this->listed(20000201, 'uuid-a', '6/2026', [['Hosting', 22000]]),
            $this->listed(20000202, 'uuid-b', '7/2026', [['Hosting', 22000]]),
        ]);

        $this->draft($client)
            ->expectsOutputToContain('Infakt invoices that may be it: 6/2026 (draft), 7/2026 (draft)')
            ->assertFailed();

        $this->assertSame(InfaktDraftRequest::STATUS_UNCONFIRMED, InfaktDraftRequest::sole()->status);
        $this->assertCount(0, $this->posts());
    }

    public function test_a_hand_made_invoice_with_the_same_total_is_not_taken_for_the_draft(): void
    {
        $client = $this->maintenanceClient('Hosting');
        $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1, fingerprint: $this->sent('Hosting', 22000));
        $this->fakeInfakt(posts: [], statuses: [], lookup: [
            $this->listed(20000203, 'uuid-hand', '8/2026', [['Consulting, June', 22000]]),
        ]);

        $this->draft($client)
            ->expectsOutputToContain('Infakt invoices that may be it: 8/2026 (draft)')
            ->assertFailed();

        $request = InfaktDraftRequest::sole();
        $this->assertSame(InfaktDraftRequest::STATUS_UNCONFIRMED, $request->status);
        $this->assertNull($request->invoice_uuid);
    }

    public function test_two_groups_with_equal_lines_never_share_one_invoice(): void
    {
        $client = $this->maintenanceClient('Maintenance');
        $client->retainers()->create([
            'account_id' => $this->account->id, 'monthly_hours' => 0, 'monthly_fee' => 220, 'currency' => 'PLN',
            'invoice_group' => 2, 'description' => 'Maintenance', 'effective_from' => '2025-01-01',
        ]);
        $same = $this->sent('Maintenance', 22000);
        // Group 1 was accepted and is being built; group 2 lost its answer. Both sent the same lines.
        $this->row($client, InfaktDraftRequest::STATUS_SUBMITTED, attempt: 1, reference: 'ref-1', fingerprint: $same);
        $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1, fingerprint: $same, group: 2);
        $this->fakeInfakt(posts: [], statuses: [201], lookup: [
            $this->listed(20000204, 'uuid-x', '6/2026', [['Maintenance', 22000]]),
        ]);

        $this->draft($client)
            ->expectsOutputToContain('Invoice group 1: already drafted earlier, skipped')
            ->expectsOutputToContain('Invoice group 2: not sent: earlier request unconfirmed')
            ->assertFailed();

        $rows = InfaktDraftRequest::orderBy('invoice_group')->get();
        $this->assertSame([InfaktDraftRequest::STATUS_CREATED, InfaktDraftRequest::STATUS_UNCONFIRMED], $rows->pluck('status')->all());
        $this->assertSame(['uuid-x', null], $rows->pluck('invoice_uuid')->all());
        $this->assertCount(0, $this->posts());
    }

    public function test_one_invoice_cannot_be_recorded_for_two_groups_even_when_writes_race(): void
    {
        $client = $this->maintenanceClient('Hosting');
        $lost = $this->row($client, InfaktDraftRequest::STATUS_UNCONFIRMED, attempt: 1, fingerprint: $this->sent('Hosting', 22000));
        $other = $this->row($client, InfaktDraftRequest::STATUS_CREATED, attempt: 1, fingerprint: $this->sent('Other', 1000), group: 2);
        $this->fakeInfakt(posts: [], statuses: [], lookup: [
            $this->listed(20000205, 'uuid-race', '6/2026', [['Hosting', 22000]]),
        ]);

        // Another process records the same invoice on another group after this run has read the rows.
        $looked = false;
        $raced = false;
        Http::fake(['api.infakt.pl/v3/invoices.json*' => function () use (&$looked) {
            $looked = true;

            return Http::response(['entities' => [$this->listed(20000205, 'uuid-race', '6/2026', [['Hosting', 22000]])]]);
        }]);
        DB::listen(function (QueryExecuted $query) use (&$looked, &$raced, $other): void {
            if ($looked && ! $raced && str_starts_with(mb_strtolower($query->sql), 'select') && str_contains($query->sql, 'infakt_draft_requests')) {
                $raced = true;
                DB::table('infakt_draft_requests')->where('id', $other->id)->update(['invoice_uuid' => 'uuid-race']);
            }
        });

        $this->draft($client)->expectsOutputToContain('not sent: earlier request unconfirmed')->assertFailed();

        $this->assertTrue($raced);
        $this->assertSame(InfaktDraftRequest::STATUS_UNCONFIRMED, $lost->fresh()->status);
        $this->assertNull($lost->fresh()->invoice_uuid);
        $this->assertSame(1, InfaktDraftRequest::where('invoice_uuid', 'uuid-race')->count());
    }

    public function test_a_run_that_failed_half_way_resumes_with_the_missing_group_only(): void
    {
        $client = $this->maintenanceClient('Site A');
        $client->retainers()->create([
            'account_id' => $this->account->id, 'monthly_hours' => 0, 'monthly_fee' => 150, 'currency' => 'PLN',
            'invoice_group' => 2, 'description' => 'Site B', 'effective_from' => '2025-01-01',
        ]);
        // Group 2 is rejected with Infakt's validation error, which creates nothing, so it may be sent again.
        $this->fakeInfakt(posts: [['ref-1', 202], [null, 422], ['ref-3', 202]], statuses: [201, 201]);

        $this->draft($client)->expectsOutputToContain('Infakt API error')->assertFailed();
        $this->assertSame(
            [1 => InfaktDraftRequest::STATUS_CREATED, 2 => InfaktDraftRequest::STATUS_FAILED],
            InfaktDraftRequest::orderBy('invoice_group')->pluck('status', 'invoice_group')->all(),
        );

        $this->draft($client)
            ->expectsOutputToContain('Invoice group 1: already drafted earlier, skipped')
            ->expectsOutputToContain('Invoice group 2: draft created')
            ->doesntExpectOutputToContain('Delete')
            ->assertSuccessful();

        $posts = $this->posts();
        $this->assertCount(3, $posts);
        $this->assertSame('Site B', $posts[2][0]['invoice']['services'][0]['name']);
        $this->assertSame(1, Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/invoices.json'))->count());
    }

    #[DataProvider('uncertainAnswers')]
    public function test_an_uncertain_answer_blocks_the_group_until_the_owner_resends(int $status, ?string $reference): void
    {
        $client = $this->maintenanceClient('x');
        $this->fakeInfakt(posts: [[$reference, $status], ['ref-2', 202]], statuses: [201]);

        $this->draft($client)->assertFailed();
        $this->assertSame(InfaktDraftRequest::STATUS_UNCONFIRMED, InfaktDraftRequest::sole()->status);

        // An ordinary rerun sends nothing: the draft may already exist in Infakt.
        $this->draft($client)->expectsOutputToContain('not sent: earlier request unconfirmed')->assertFailed();
        $this->assertCount(1, $this->posts());
    }

    public function test_a_draft_infakt_rejects_is_sent_again_on_the_next_run(): void
    {
        $client = $this->maintenanceClient('x');
        $this->fakeInfakt(posts: [['ref-1', 202], ['ref-2', 202]], statuses: [422, 201]);

        $this->draft($client)->expectsOutputToContain('Infakt rejected the draft')->assertFailed();
        $this->assertSame(InfaktDraftRequest::STATUS_FAILED, InfaktDraftRequest::sole()->status);

        $this->draft($client)->expectsOutputToContain('draft created')->assertSuccessful();
        $this->assertCount(2, $this->posts());
    }

    public function test_an_invoice_made_by_hand_for_the_month_stops_the_run_without_advice_to_delete(): void
    {
        $client = $this->maintenanceClient('x');
        $this->fakeInfakt(posts: [], statuses: [], lookup: [
            ['id' => 20000100, 'client_id' => 10000002, 'sale_date' => '2026-06-30', 'status' => 'paid', 'number' => '6/2026'],
        ]);

        $this->draft($client)
            ->expectsOutputToContain('6/2026 (paid)')
            ->doesntExpectOutputToContain('Delete')
            ->assertFailed();

        $this->assertCount(0, $this->posts());
        $this->assertSame(0, InfaktDraftRequest::count());
    }

    public function test_an_invoice_for_another_month_does_not_block_the_draft(): void
    {
        $client = $this->maintenanceClient('x');
        $this->fakeInfakt(posts: [['ref-1', 202]], statuses: [201], lookup: [
            ['id' => 20000100, 'client_id' => 10000002, 'sale_date' => '2026-05-31', 'status' => 'paid', 'number' => '5/2026'],
        ]);

        $this->draft($client)->assertSuccessful();

        $this->assertCount(1, $this->posts());
    }

    public function test_a_failed_lookup_refuses_rather_than_risking_a_duplicate(): void
    {
        $client = $this->maintenanceClient('x');
        $this->service();
        Http::preventStrayRequests();
        Http::fake(['api.infakt.pl/v3/invoices.json*' => Http::response('down', 503)]);

        $this->draft($client)->assertFailed();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    /**
     * @param  list<array{0: ?string, 1: int}>  $posts  task reference and HTTP status per POST, in order (status 0 = dropped connection)
     * @param  list<int>  $statuses  processing_code per status check, in order
     * @param  list<array<string, mixed>>  $lookup  what invoices.json lists
     */
    private function fakeInfakt(array $posts, array $statuses, array $lookup = [], ?Closure $onPost = null, ?Closure $onStatus = null): void
    {
        $this->service();
        Sleep::fake();
        Http::preventStrayRequests();

        $postQueue = Http::sequence();
        foreach ($posts as [$reference, $status]) {
            match (true) {
                $status === 0 => $postQueue->pushFailedConnection('Connection reset by peer'),
                $reference === null => $postQueue->push($status === 422 ? ['errors' => ['services' => ['invalid']]] : '<html>gateway</html>', $status),
                default => $postQueue->push(['invoice_task_reference_number' => $reference, 'processing_code' => 100], $status),
            };
        }
        $statusQueue = Http::sequence();
        foreach ($statuses as $code) {
            $statusQueue->push(match ($code) {
                201 => ['processing_code' => 201, 'processing_description' => 'Faktura stworzona', 'invoice_uuid' => 'uuid-'.($posts[0][0] ?? 'x')],
                422 => ['processing_code' => 422, 'processing_description' => 'Nie udało się stworzyć faktury', 'invoice_errors' => ['payment_method' => ['bad']]],
                default => ['processing_code' => $code, 'processing_description' => 'Zlecenie jest w trakcie przetwarzania'],
            });
        }

        Http::fake([
            'api.infakt.pl/v3/invoices.json*' => Http::response(['entities' => $lookup]),
            'api.infakt.pl/v3/async/invoices.json' => $onPost ?? $postQueue,
            'api.infakt.pl/v3/async/invoices/status/*' => $onStatus ?? $statusQueue,
        ]);
    }

    private function row(Client $client, string $status, int $attempt, ?DateTimeInterface $lease = null, ?string $reference = null, ?string $fingerprint = null, int $group = 1): InfaktDraftRequest
    {
        return InfaktDraftRequest::create([
            'account_id' => $this->account->id, 'client_id' => $client->id, 'period' => '2026-06', 'invoice_group' => $group,
            'status' => $status, 'attempt' => $attempt, 'lease_expires_at' => $lease, 'task_reference' => $reference,
            'payload_fingerprint' => $fingerprint,
        ]);
    }

    /** The fingerprint of a one-line PLN draft as the run sends it. */
    private function sent(string $name, int $net): string
    {
        return InfaktDraftRun::fingerprint('PLN', [['name' => $name, 'unit_net_price' => $net, 'quantity' => 1, 'tax_symbol' => '23']]);
    }

    /**
     * An invoice as Infakt lists it, with its line items.
     *
     * @param  list<array{0: string, 1: int}>  $lines
     * @return array<string, mixed>
     */
    private function listed(int $id, string $uuid, string $number, array $lines): array
    {
        return [
            'id' => $id, 'uuid' => $uuid, 'number' => $number, 'client_id' => 10000002, 'sale_date' => '2026-06-30',
            'status' => 'draft', 'currency' => 'PLN', 'net_price' => array_sum(array_column($lines, 1)),
            'services' => array_map(fn (array $l): array => ['name' => $l[0], 'unit_net_price' => $l[1], 'quantity' => 1.0, 'tax_symbol' => '23', 'unit' => 'usł.'], $lines),
        ];
    }

    private function draft(Client $client): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('infakt:draft-invoice', ['client' => (string) $client->id, '--period' => '2026-06']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{0: Request, 1: mixed}>
     */
    private function posts()
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST')->values();
    }

    private function service(): InfaktService
    {
        return new InfaktService(Integration::firstOrCreate(
            ['account_id' => $this->account->id, 'provider' => 'infakt'],
            ['api_key' => 'test-key', 'is_enabled' => true],
        ));
    }

    private function maintenanceClient(?string $description, float $fee = 220): Client
    {
        $client = $this->account->clients()->create([
            'name' => 'Roofs Ltd',
            'month_close_type' => 'maintenance',
            'external_ids' => ['infakt' => '10000002'],
            'maintenance_invoice_description' => $description,
        ]);
        $client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 0,
            'monthly_fee' => $fee,
            'currency' => 'PLN',
            'effective_from' => '2025-01-01',
            'effective_to' => null,
        ]);

        return $client;
    }
}

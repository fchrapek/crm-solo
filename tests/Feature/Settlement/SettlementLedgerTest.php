<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\User;
use App\Services\Clients\ClientBillingQuery;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use App\Support\LocalCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Support\Header;
use Tests\TestCase;

/** Rozliczenia's ledger: settlement and report delivery as separate facts per month. */
final class SettlementLedgerTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(30, '2026-01-01');
    }

    public function test_the_ledger_keeps_settlement_and_report_delivery_apart(): void
    {
        $this->setUpSettlement(baseline: '2026-08', nowUtc: '2026-11-20 10:00:00');
        $this->retainer(30, '2026-01-01');
        $this->report('2026-07', opening: 0, contracted: 30, actual: 24, status: ClientReport::STATUS_SENT);
        $this->settle('2026-08');
        $this->report('2026-09', opening: 6, contracted: 30, actual: 32, status: ClientReport::STATUS_FINALIZED);
        $september = $this->settle('2026-09');

        $rows = collect($this->billing()['ledger']['rows'])->keyBy('period');

        $this->assertSame(['2026-10', '2026-09', '2026-08', '2026-07'], $rows->keys()->all());
        $this->assertSame(['open', true, null, null], [$rows['2026-10']['settlement'], $rows['2026-10']['active'], $rows['2026-10']['report_id'], $rows['2026-10']['closure_id']]);
        $this->assertSame(['closed', '2026-11-20', 'finalized', null], [$rows['2026-09']['settlement'], $rows['2026-09']['closed_on'], $rows['2026-09']['status'], $rows['2026-09']['sent_on']]);
        $this->assertSame($september->id, $rows['2026-09']['closure_id'], 'the latest closure reopens');
        $this->assertSame(['closed', null, null], [$rows['2026-08']['settlement'], $rows['2026-08']['report_id'], $rows['2026-08']['closure_id']], 'a closure-only row, not the one that reopens');
        $this->assertSame([null, 'sent', true], [$rows['2026-07']['settlement'], $rows['2026-07']['status'], $rows['2026-07']['before_baseline']], 'before the baseline only the report speaks');
        $this->assertEquals(4.0, $rows['2026-09']['closing']);
        $this->assertEquals(4.0, $rows['2026-10']['opening']);
    }

    public function test_an_unclosed_month_behind_is_open_and_nothing_reopens(): void
    {
        $this->entry('2026-09-10 08:00:00', 600);

        $billing = $this->billing();
        $september = $billing['ledger']['rows'][0];

        $this->assertSame(['2026-09', 'open', true, null], [$september['period'], $september['settlement'], $september['active'], $september['closure_id']]);
        $this->assertSame(['active_period' => '2026-09', 'current_period' => '2026-10', 'open_behind' => true], $billing['settlement']);
    }

    public function test_reopen_carries_the_refusal_of_a_later_report_already_out(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $this->settle('2026-09');
        $october = $this->report('2026-10', opening: 30, contracted: 30, actual: 10, status: ClientReport::STATUS_SENT);

        $september = collect($this->billing()['ledger']['rows'])->firstWhere('period', '2026-09');

        $this->assertSame('The report for October 2026 is already finalized or sent; reopen it first.', $september['reopen_refusal']);
        $this->assertSame(['report_id' => $october->id, 'period' => '2026-10'], $september['reopen_blocker']);
    }

    public function test_a_reopenable_month_without_a_later_report_out_names_no_blocker(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $this->settle('2026-09');
        $this->report('2026-10', opening: 30, contracted: 30, actual: 10);

        $september = collect($this->billing()['ledger']['rows'])->firstWhere('period', '2026-09');

        $this->assertNotNull($september['closure_id']);
        $this->assertSame([null, null], [$september['reopen_refusal'], $september['reopen_blocker']]);
    }

    public function test_the_opening_balance_edit_is_offered_only_while_the_rules_let_it_change(): void
    {
        $september = $this->report('2026-09', opening: 0, contracted: 30, actual: 20);
        $this->assertSame($september->id, $this->billing()['opening_balance']['report_id']);

        $this->settle('2026-09');

        $this->assertNull($this->billing()['opening_balance']);
    }

    public function test_only_the_shown_page_of_months_has_its_figures_read(): void
    {
        $this->setUpSettlement(baseline: '2026-10', nowUtc: '2026-10-20 10:00:00');
        $this->retainer(30, '2024-01-01');
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $props = app(ClientBillingQuery::class)->props($this->client);
            $this->assertCount(ClientBillingQuery::LEDGER_ROWS, $props['billing_ledger']()['rows']);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        foreach (range(1, 14) as $ago) {
            $this->report(Carbon::parse('2026-10-01')->subMonths($ago)->format('Y-m'), opening: 0, contracted: 30, actual: 20, status: ClientReport::STATUS_SENT);
        }
        $fourteen = $measure();
        foreach (range(15, 30) as $ago) {
            $this->report(Carbon::parse('2026-10-01')->subMonths($ago)->format('Y-m'), opening: 0, contracted: 30, actual: 20, status: ClientReport::STATUS_SENT);
        }

        $this->assertSame($fourteen, $measure());
        $this->assertSame('2', $this->billing()['ledger']['next']);
    }

    public function test_starsze_pages_through_every_month_closure_only_rows_included_and_ends_on_the_entry_row(): void
    {
        $this->setUpSettlement(baseline: '2025-03', nowUtc: '2026-10-20 10:00:00');
        $this->retainer(10, '2025-01-01');
        foreach (LocalCalendar::monthsBetween('2025-03', '2026-10') as $period) {
            $this->settle($period);
        }

        $first = $this->billing();
        $this->assertCount(ClientBillingQuery::LEDGER_ROWS, $first['ledger']['rows']);
        $this->assertSame('2', $first['ledger']['next']);
        $this->assertNull($first['ledger']['start']);

        $rest = $this->olderPage('2');
        $this->assertSame(['2025-06', '2025-05', '2025-04', '2025-03'], array_column(array_slice($rest['rows'], -4), 'period'));
        $this->assertNull($rest['next']);
        $this->assertEquals(['date' => '2025-03-01', 'balance' => 0], $rest['start']);

        $all = [...$first['ledger']['rows'], ...$rest['rows']];
        $this->assertCount(20, array_unique(array_column($all, 'period')), 'nineteen closed months and the open one, each once');
        $closed = array_filter($all, fn (array $row): bool => $row['settlement'] === 'closed');
        $this->assertCount(19, $closed);
        $this->assertSame([null], array_values(array_unique(array_column($closed, 'report_id'))), 'closure-only rows');
        $this->assertSame(['2026-09'], array_column(array_filter($all, fn (array $row): bool => $row['closure_id'] !== null), 'period'), 'only the latest closure reopens');
        $this->assertSame(['2026-10', 'open', true], [$all[0]['period'], $all[0]['settlement'], $all[0]['active']]);
    }

    public function test_a_ledger_page_is_scoped_to_the_account(): void
    {
        $stranger = User::factory()->create(['account_id' => Account::create(['name' => 'Other'])->id, 'owner' => true]);

        $this->actingAs($stranger)->get("/clients/{$this->client->id}/rozliczenia?miesiace=2")->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function olderPage(string $next): array
    {
        return $this->actingAs($this->user)
            ->withHeaders([
                Header::INERTIA => 'true',
                Header::VERSION => (string) app(HandleInertiaRequests::class)->version(request()),
                Header::PARTIAL_COMPONENT => 'clients/show',
                Header::PARTIAL_ONLY => 'billing_ledger',
            ])
            ->get("/clients/{$this->client->id}/rozliczenia?miesiace={$next}")
            ->assertOk()
            ->assertJsonMissingPath('props.billing')
            ->json('props.billing_ledger');
    }

    /** @return array<string, mixed> */
    private function billing(): array
    {
        $props = $this->actingAs($this->user)->get("/clients/{$this->client->id}/rozliczenia")->assertOk()->viewData('page')['props'];

        return [...$props['billing'], 'ledger' => $props['billing_ledger']];
    }

    private function settle(string $period): ClientMonthClosure
    {
        $settlement = app(MonthSettlement::class);
        $result = $settlement->close($this->client, $period, $settlement->preview($this->client, $period)['hash'], $this->user);
        $this->assertSame(SettlementOutcome::Applied, $result->outcome, $result->message);

        return $result->closure;
    }
}

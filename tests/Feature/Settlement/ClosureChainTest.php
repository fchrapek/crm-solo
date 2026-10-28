<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\ClientReport;
use App\Services\Clients\HoursBank;
use App\Services\Clients\HoursBankMonth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ClosureChainTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(30, '2026-01-01');
    }

    public function test_a_closed_month_reads_its_snapshot_while_logged_time_stays_live(): void
    {
        $this->report('2026-09', opening: 1, contracted: 30, actual: 20);
        $this->closure('2026-09', opening: 9.5, contracted: 30, used: 51);
        $this->entry('2026-09-10 08:00:00', 90);

        $month = $this->bank()->month($this->client, '2026-09');

        $this->assertSame(HoursBankMonth::SOURCE_CLOSURE, $month->source);
        $this->assertTrue($month->isClosed());
        $this->assertSame(9.5, $month->openingBalanceHours);
        $this->assertSame(51.0, $month->usedHours);
        $this->assertSame(-11.5, $month->closingBalanceHours());
        $this->assertSame(1.5, $month->loggedHours);
        $this->assertNotNull($month->reportId, 'the report link stays, the figures do not come from it');
    }

    public function test_the_next_month_opens_from_the_closure_not_the_report_of_the_same_month(): void
    {
        $this->report('2026-09', opening: 0, contracted: 30, actual: 20);
        $this->closure('2026-09', opening: 9.5, contracted: 30, used: 51);

        $october = $this->bank()->month($this->client, '2026-10');

        $this->assertSame(-11.5, $october->openingBalanceHours);
        $this->assertSame(-11.5, $this->bank()->openingBalance($this->client, '2026-10'));
    }

    public function test_a_month_with_nothing_settled_is_skipped_by_the_carry(): void
    {
        $this->closure('2026-09', opening: 0, contracted: 30, used: 25);

        $this->assertSame(5.0, $this->bank()->openingBalance($this->client, '2026-11'));
    }

    public function test_a_later_report_carries_past_an_earlier_closure(): void
    {
        $this->closure('2026-09', opening: 0, contracted: 30, used: 25);
        $this->report('2026-10', opening: 5, contracted: 30, actual: 40);

        $this->assertSame(-5.0, $this->bank()->openingBalance($this->client, '2026-11'));
    }

    public function test_a_closed_month_reads_its_snapshot_after_its_position_was_deleted(): void
    {
        $this->closure('2026-09', opening: 2, contracted: 30, used: 10, cap: 40);
        $this->client->retainers()->delete();

        $month = $this->bank()->month($this->client, '2026-09');
        $this->assertSame(30.0, $month->contractedHours);
        $this->assertSame(40.0, $month->rolloverCapHours);
        $this->assertSame(22.0, $month->closingBalanceHours());
    }

    public function test_a_cap_and_a_debt_carry_through_the_closure(): void
    {
        $this->closure('2026-09', opening: 30, contracted: 30, used: 5, cap: 40);
        $this->assertSame(35.0, $this->bank()->openingBalance($this->client, '2026-10'));

        $this->closure('2026-10', opening: 35, contracted: 30, used: 90, cap: 40);
        $this->assertSame(-50.0, $this->bank()->openingBalance($this->client, '2026-11'));
    }

    public function test_before_the_baseline_a_partial_report_still_carries(): void
    {
        $this->report('2026-08', opening: 0, contracted: 30, actual: 5, start: '2026-08-01', end: '2026-08-15', type: ClientReport::PERIOD_WEEK);

        $this->assertSame(25.0, $this->bank()->openingBalance($this->client, '2026-09'));
    }

    public function test_from_the_baseline_on_a_partial_month_never_settles(): void
    {
        $this->report('2026-08', opening: 0, contracted: 30, actual: 10);
        $this->report('2026-09', opening: 99, contracted: 30, actual: 1, status: ClientReport::STATUS_SENT, start: '2026-09-01', end: '2026-09-30');
        ClientReport::query()->whereDate('period_start', '2026-09-01')->update(['period_start' => '2026-09-08']);

        $this->assertSame(20.0, $this->bank()->openingBalance($this->client, '2026-10'));
    }

    public function test_rounded_report_hours_are_what_the_month_freezes_on(): void
    {
        $this->entry('2026-09-10 08:00:00', 567);
        $this->report('2026-09', opening: 0, contracted: 30, actual: 10);

        $september = $this->bank()->month($this->client, '2026-09');
        $this->assertSame(HoursBankMonth::SOURCE_REPORT, $september->source);
        $this->assertSame(10.0, $september->usedHours);
        $this->assertSame(9.45, $september->loggedHours);
    }

    public function test_months_for_mixed_periods_cost_queries_per_period_not_per_client(): void
    {
        $count = function (array $periods): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bank()->months($periods);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $others = collect(range(1, 6))->map(fn (int $i) => $this->account->clients()->create(['name' => "Other {$i}", 'currency' => 'PLN']));
        $two = [$this->client->id => '2026-09', $others[0]->id => '2026-10'];
        $many = [$this->client->id => '2026-09', ...$others->mapWithKeys(fn ($c, int $i): array => [$c->id => $i % 2 === 0 ? '2026-10' : '2026-09'])->all()];

        $this->assertSame($count($two), $count($many));

        $months = $this->bank()->months($many);
        $this->assertSame(array_keys($many), array_keys($months));
        $this->assertSame(array_values($many), array_values(array_map(fn (HoursBankMonth $m): string => $m->period, $months)));
    }

    public function test_the_ledger_lists_closed_reported_and_active_months_newest_first(): void
    {
        $this->travelTo(Carbon::parse('2026-12-10 12:00:00', 'UTC'));
        $this->report('2026-08', opening: 0, contracted: 30, actual: 10, status: ClientReport::STATUS_SENT);
        $sept = $this->closure('2026-09', opening: 20, contracted: 30, used: 25);
        $this->report('2026-10', opening: 25, contracted: 30, actual: 30, status: ClientReport::STATUS_FINALIZED);
        $stranger = $this->account->clients()->create(['name' => 'Stranger', 'currency' => 'PLN']);
        $this->closure('2026-07', opening: 0, contracted: 5, used: 1, client: $stranger);

        $rows = $this->bank()->ledger($this->client);

        $this->assertSame(['2026-10', '2026-09', '2026-08'], array_map(fn ($row) => $row->period, $rows));
        [$october, $september, $august] = $rows;
        $this->assertTrue($october->active, 'October is the oldest unclosed eligible month');
        $this->assertNull($october->closure);
        $this->assertSame(ClientReport::STATUS_FINALIZED, $october->report?->status);
        $this->assertSame($sept->id, $september->closure?->id);
        $this->assertNull($september->report, 'a closure-only row has no report');
        $this->assertSame(25.0, $october->month?->closingBalanceHours());
        $this->assertTrue($august->beforeBaseline);
        $this->assertFalse($august->eligible);
    }

    public function test_a_ledger_page_hydrates_only_its_own_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = $this->bank()->ledgerPage($this->client, page: 1, perPage: 6);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertCount(6, $page->items());

            return $queries;
        };
        foreach (range(1, 8) as $month) {
            $this->report(sprintf('2025-%02d', $month), opening: 0, contracted: 30, actual: 1);
        }
        $few = $count();

        foreach (range(1, 12) as $month) {
            $this->report(sprintf('2024-%02d', $month), opening: 0, contracted: 30, actual: 1);
        }

        $this->assertSame($few, $count());
        $page = $this->bank()->ledgerPage($this->client, page: 2, perPage: 6);
        $this->assertSame(21, $page->total(), 'twenty reported months and the active one');
        $this->assertSame(['2025-03', '2025-02', '2025-01', '2024-12', '2024-11', '2024-10'], array_map(fn ($row) => $row->period, $page->items()));
    }

    private function bank(): HoursBank
    {
        return app(HoursBank::class);
    }
}

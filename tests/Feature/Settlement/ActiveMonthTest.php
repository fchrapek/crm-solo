<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\Account;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientRetainer;
use App\Services\Clients\HoursBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ActiveMonthTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
    }

    public function test_an_unclosed_september_stays_active_across_the_first_of_november(): void
    {
        $this->retainer(10, '2026-01-01');

        $this->assertSame('2026-09', $this->bank()->activeMonth($this->client));

        // 00:30 on 1 November in Warsaw, still October in UTC.
        $state = $this->bank()->settlement($this->client, Carbon::parse('2026-10-31 23:30:00', 'UTC'));
        $this->assertSame('2026-11', $state->currentPeriod);
        $this->assertSame('2026-09', $state->activePeriod);
        $this->assertSame(['2026-09', '2026-10'], $state->eligible);
        $this->assertTrue($state->openBehind());
    }

    public function test_the_running_month_is_active_once_every_elapsed_month_is_closed(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->closure('2026-09', 0, 10, 4);

        $state = $this->bank()->settlement($this->client);
        $this->assertSame('2026-10', $state->activePeriod);
        $this->assertFalse($state->openBehind());
    }

    public function test_reopening_the_only_closure_months_later_brings_that_month_back(): void
    {
        $this->retainer(10, '2026-01-01');
        $closure = $this->closure('2026-09', 0, 10, 4);
        $later = Carbon::parse('2027-02-10 12:00:00', 'UTC');

        $this->assertSame('2026-10', $this->bank()->activeMonth($this->client, $later));

        $closure->delete();

        $this->assertSame('2026-09', $this->bank()->activeMonth($this->client, $later));
    }

    public function test_months_without_a_retainer_are_skipped(): void
    {
        $this->retainer(10, '2026-01-01', to: '2026-10-01');
        $this->retainer(6, '2026-12-01');
        $this->closure('2026-09', 0, 10, 4);

        $state = $this->bank()->settlement($this->client, Carbon::parse('2027-02-10 12:00:00', 'UTC'));
        $this->assertSame(['2026-09', '2026-12', '2027-01'], $state->eligible);
        $this->assertSame('2026-12', $state->activePeriod);
    }

    public function test_a_retainer_starting_mid_month_owes_its_first_close_for_the_next_month(): void
    {
        $this->retainer(10, '2026-10-15');

        $state = $this->bank()->settlement($this->client, Carbon::parse('2026-12-05 12:00:00', 'UTC'));
        $this->assertSame(['2026-11'], $state->eligible);
        $this->assertSame('2026-11', $state->activePeriod);
    }

    public function test_an_account_created_mid_month_starts_settling_that_month_but_a_retainer_from_that_day_skips_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00', 'UTC'));
        $account = Account::create(['name' => 'New']);
        $client = $account->clients()->create(['name' => 'New client', 'currency' => 'PLN']);
        $this->retainer(10, '2026-10-15', client: $client);

        $state = $this->bank()->settlement($client, Carbon::parse('2026-12-05 12:00:00', 'UTC'));
        $this->assertSame('2026-10', $state->baseline);
        $this->assertSame(['2026-11'], $state->eligible);
    }

    public function test_a_retainer_ending_mid_month_still_owes_that_month(): void
    {
        $this->retainer(10, '2026-01-01', to: '2026-10-15');

        $state = $this->bank()->settlement($this->client, Carbon::parse('2026-12-05 12:00:00', 'UTC'));
        $this->assertSame(['2026-09', '2026-10'], $state->eligible);
    }

    public function test_an_ended_retainer_leaves_its_elapsed_months_in_the_backlog(): void
    {
        $this->retainer(10, '2026-01-01', to: '2026-10-01');

        $state = $this->bank()->settlement($this->client, Carbon::parse('2026-12-05 12:00:00', 'UTC'));
        $this->assertSame(['2026-09'], $state->eligible);
        $this->assertSame('2026-09', $state->activePeriod);

        $this->closure('2026-09', 0, 10, 4);
        $this->assertSame('2026-12', $this->bank()->activeMonth($this->client, Carbon::parse('2026-12-05 12:00:00', 'UTC')));
    }

    public function test_an_inactive_position_owes_nothing(): void
    {
        $this->retainer(10, '2026-01-01', active: false);

        $this->assertSame([], $this->bank()->settlement($this->client)->eligible);
        $this->assertSame('2026-10', $this->bank()->activeMonth($this->client));
    }

    public function test_months_before_the_baseline_are_never_owed(): void
    {
        $this->retainer(10, '2025-01-01');

        $this->assertSame(['2026-09'], $this->bank()->settlement($this->client)->eligible);
    }

    public function test_the_backlog_runs_across_the_new_year(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-11']]);
        $this->retainer(10, '2026-01-01');

        $state = $this->bank()->settlement($this->client, Carbon::parse('2027-01-10 12:00:00', 'UTC'));
        $this->assertSame(['2026-11', '2026-12'], $state->eligible);
        $this->assertSame('2027-01', $state->currentPeriod);
    }

    public function test_the_month_turns_at_warsaw_midnight_not_utc(): void
    {
        $this->retainer(10, '2026-01-01');
        $this->account->update(['settings' => ['settlement_from' => '2026-10']]);

        $beforeMidnight = $this->bank()->settlement($this->client, Carbon::parse('2026-10-31 22:59:00', 'UTC'));
        $this->assertSame('2026-10', $beforeMidnight->currentPeriod);
        $this->assertSame('2026-10', $beforeMidnight->activePeriod);

        $afterMidnight = $this->bank()->settlement($this->client, Carbon::parse('2026-10-31 23:01:00', 'UTC'));
        $this->assertSame('2026-11', $afterMidnight->currentPeriod);
        $this->assertSame('2026-10', $afterMidnight->activePeriod);
    }

    public function test_a_reopened_month_stays_owed_after_its_position_is_gone(): void
    {
        $position = $this->retainer(10, '2026-01-01');
        $closure = $this->closure('2026-09', 0, 10, 4);
        ClientMonthSettlementEvent::create([
            'client_id' => $this->client->id, 'period' => '2026-09', 'action' => ClientMonthSettlementEvent::ACTION_REOPEN,
            'closure_id' => $closure->id, 'snapshot' => $closure->snapshot, 'created_at' => now(),
        ]);
        $closure->delete();
        ClientRetainer::query()->whereKey($position->id)->delete();

        $state = $this->bank()->settlement($this->client);
        $this->assertSame(['2026-09'], $state->eligible);
        $this->assertSame('2026-09', $state->activePeriod);
    }

    public function test_active_months_for_a_page_cost_the_same_queries_as_for_one_client(): void
    {
        $this->retainer(10, '2026-01-01');
        $count = function (array $clients): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bank()->activeMonths($clients);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $one = $count([$this->client]);

        $clients = [$this->client];
        foreach (range(1, 6) as $i) {
            $other = $this->account->clients()->create(['name' => "Other {$i}", 'currency' => 'PLN']);
            $this->retainer($i, '2026-01-01', client: $other);
            if ($i % 2 === 0) {
                $this->closure('2026-09', 0, $i, 1, client: $other);
            }
            $clients[] = $other;
        }

        $this->assertSame($one, $count($clients));
        $months = $this->bank()->activeMonths($clients);
        $this->assertSame(['2026-09', '2026-09', '2026-10', '2026-09', '2026-10', '2026-09', '2026-10'], array_values($months));
    }

    private function bank(): HoursBank
    {
        return app(HoursBank::class);
    }
}

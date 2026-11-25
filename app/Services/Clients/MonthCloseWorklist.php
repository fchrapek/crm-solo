<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonthCloseRun;
use App\Support\LocalCalendar;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Who is in a period's month close: enrolled clients, an unfinished run of the period, and clients still owing that hours month. */
final class MonthCloseWorklist
{
    public function __construct(private readonly HoursBank $bank) {}

    /**
     * Clients by name, each with the period's run (steps and their sites loaded) and its financial close row.
     *
     * @return list<array{client: Client, run: ?MonthCloseRun, settlement: array{state: string, period: string, active_period: string, closed_on: string|null, closing: float|null}|null, template_total: int, can_start_checklist: bool}>
     */
    public function rows(Account $account, string $period): array
    {
        $states = $this->states($account);
        $clients = $this->held($account, $states, $period)
            ->orderBy('name')
            ->with([
                'monthCloseSites:id,client_id,name',
                'monthCloseRuns' => fn ($query) => $query->where('period', $period)->with('steps.project:id,name'),
            ])
            ->get();

        $settlements = $this->settlementRows(array_intersect_key($states, $clients->keyBy('id')->all()), $period);

        return $clients->map(fn (Client $client): array => [
            'client' => $client,
            'run' => $client->monthCloseRuns->first(),
            'settlement' => $settlements[$client->id] ?? null,
            'template_total' => count(MonthCloseRun::stepTemplateFor($client)),
            'can_start_checklist' => $client->month_close_type !== null && $client->include_in_month_close,
        ])->values()->all();
    }

    /** How many clients the close holds in any period: the set the Month close switch refuses to leave behind. */
    public function heldCount(Account $account): int
    {
        return $this->held($account, $this->states($account), null)->count();
    }

    /** Whether the close holds this client in any period, so its close settings stay in sight even with the module off. */
    public function holds(Client $client): bool
    {
        return $this->held($client->account, $this->bank->settlements([$client->id]), null)->whereKey($client->id)->exists();
    }

    /**
     * Enrolled clients, an unfinished run and clients still owing an hours month: one period's, or any period's without one.
     *
     * @param  array<int, ClientSettlementState>  $states
     * @return HasMany<Client, Account>
     */
    private function held(Account $account, array $states, ?string $period): HasMany
    {
        $owing = $this->owingHours($states, $period);

        // A client taken out of the close keeps an unfinished run of the period reachable.
        return $account->clients()
            ->where(fn ($query) => $query
                ->where(fn ($enrolled) => $enrolled->whereNotNull('month_close_type')->where('include_in_month_close', true))
                ->orWhereHas('monthCloseRuns', fn ($run) => $run->when($period !== null, fn ($one) => $one->where('period', $period))->where('status', '!=', MonthCloseRun::STATUS_COMPLETED))
                ->orWhereIn('id', $owing));
    }

    /** @return array<int, ClientSettlementState> */
    private function states(Account $account): array
    {
        return $this->bank->settlements($account->clients()->pluck('id')->map(intval(...))->all());
    }

    /**
     * The financial close of each retainer client's month, beside the checklist and never one of its steps: closable when
     * it is the month being settled, its closing date once closed, the month to close first when an earlier one is open.
     * The month in progress and months without a retainer get none.
     *
     * @param  array<int, ClientSettlementState>  $states
     * @return array<int, array{state: string, period: string, active_period: string, closed_on: string|null, closing: float|null}>
     */
    private function settlementRows(array $states, string $period): array
    {
        $rows = [];
        foreach ($states as $id => $state) {
            if ($period >= $state->currentPeriod || ! $state->isEligible($period)) {
                continue;
            }
            $closure = $state->closureFor($period);
            $rows[$id] = [
                'state' => match (true) {
                    $closure !== null => 'closed',
                    $period === $state->activePeriod => 'ready',
                    default => 'blocked',
                },
                'period' => $period,
                'active_period' => $state->activePeriod,
                'closed_on' => $closure?->closed_at->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
                'closing' => null,
            ];
        }

        $ready = array_keys(array_filter($rows, fn (array $row): bool => $row['state'] === 'ready'));
        foreach ($this->bank->months(array_fill_keys($ready, $period)) as $id => $month) {
            $rows[$id]['closing'] = $month->closingBalanceHours();
        }

        return $rows;
    }

    /**
     * Clients whose hours month still owed is this one (any one without a period), the same set the Clients note counts.
     *
     * @param  array<int, ClientSettlementState>  $states
     * @return list<int>
     */
    private function owingHours(array $states, ?string $period): array
    {
        return array_keys(array_filter($states, fn (ClientSettlementState $state): bool => $state->openBehind() && ($period === null || $state->activePeriod === $period)));
    }
}

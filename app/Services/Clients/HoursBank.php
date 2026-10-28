<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\TimeEntry;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** A client's retainer hours for one month, counted as its report counts them, and where the client stands in settling them. */
final class HoursBank
{
    private const array BALANCE_COLUMNS = [
        'client_reports.id',
        'client_reports.client_id',
        'client_reports.period_type',
        'client_reports.period_start',
        'client_reports.period_end',
        'client_reports.status',
        'client_reports.opening_balance_hours',
        'client_reports.contracted_hours',
        'client_reports.rollover_cap_hours',
        'client_reports.actual_hours',
    ];

    /** What the client can draw on: carried balance plus the pool, never above the ceiling. */
    public static function available(float $opening, float $contracted, ?float $cap): float
    {
        $total = $opening + $contracted;

        return round($cap !== null ? min($total, $cap) : $total, 2);
    }

    /** What carries into the next month; negative when the client overran, since overage is banked, not billed. */
    public static function closing(float $available, float $used): float
    {
        return round($available - $used, 2);
    }

    public static function forfeited(float $opening, float $contracted, ?float $cap): float
    {
        return max(0.0, round($opening + $contracted - self::available($opening, $contracted, $cap), 2));
    }

    /**
     * The closing balance of the latest settled point that ended before the period starts. A closure beats a report
     * ending the same day; from the account's baseline on, only whole-month reports count.
     */
    public function openingBalance(Client $client, CarbonInterface|string $periodStart): float
    {
        $date = is_string($periodStart) ? LocalCalendar::monthFrom($periodStart)->toDateString() : $periodStart->toDateString();

        return $this->openingBalances([$client->id], $date)[$client->id];
    }

    public function month(Client $client, string $period): HoursBankMonth
    {
        return $this->months([$client->id => $period])[$client->id];
    }

    /**
     * Each client's month, with a query count that grows with the distinct periods asked for, never with the clients.
     *
     * @param  array<int, string>  $periods  period (YYYY-MM) by client id
     * @return array<int, HoursBankMonth> keyed by client id, in the order asked
     */
    public function months(array $periods): array
    {
        $found = [];
        foreach (collect($periods)->groupBy(fn (string $period): string => $period, preserveKeys: true) as $period => $group) {
            $found += $this->monthsIn(array_map(intval(...), array_keys($group->all())), (string) $period);
        }

        $months = [];
        foreach (array_keys($periods) as $id) {
            $months[$id] = $found[$id];
        }

        return $months;
    }

    /** The oldest elapsed month that owes a close, else the month in progress. */
    public function activeMonth(Client $client, ?CarbonInterface $now = null): string
    {
        return $this->settlement($client, $now)->activePeriod;
    }

    /**
     * @param  iterable<Client>  $clients
     * @return array<int, string> keyed by client id
     */
    public function activeMonths(iterable $clients, ?CarbonInterface $now = null): array
    {
        return array_map(fn (ClientSettlementState $state): string => $state->activePeriod, $this->settlements($this->ids($clients), $now));
    }

    public function settlement(Client $client, ?CarbonInterface $now = null): ClientSettlementState
    {
        return $this->settlements([$client->id], $now)[$client->id];
    }

    /**
     * A month from the baseline up to the one before $now owes a close when a retainer position leads on its 1st, or
     * once it was ever closed: a reopened month keeps its obligation whatever happened to the position since.
     *
     * @param  list<int>  $ids
     * @return array<int, ClientSettlementState> keyed by client id
     */
    public function settlements(array $ids, ?CarbonInterface $now = null): array
    {
        if ($ids === []) {
            return [];
        }

        $current = LocalCalendar::monthOf($now ?? LocalCalendar::now());
        $baselines = $this->baselines($ids);
        $retainers = ClientRetainer::query()
            ->whereIn('client_id', $ids)
            ->where('is_active', true)
            ->get(['id', 'client_id', 'effective_from', 'effective_to'])
            ->groupBy('client_id');
        $closures = ClientMonthClosure::query()->whereIn('client_id', $ids)->orderBy('period')->get()->groupBy('client_id');
        $settled = ClientMonthSettlementEvent::query()
            ->whereIn('client_id', $ids)
            ->distinct()
            ->get(['client_id', 'period'])
            ->groupBy('client_id')
            ->map(fn (Collection $rows): array => $rows->pluck('period')->all());

        $states = [];
        foreach ($ids as $id) {
            /** @var array<string, ClientMonthClosure> $byPeriod */
            $byPeriod = ($closures->get($id) ?? collect())->keyBy('period')->all();
            $positions = $retainers->get($id) ?? collect();
            $eligible = [];
            foreach (LocalCalendar::monthsBetween($baselines[$id], $current) as $period) {
                $first = Carbon::parse(LocalCalendar::monthFrom($period)->toDateString());
                if (isset($byPeriod[$period])
                    || in_array($period, $settled->get($id, []), true)
                    || $positions->contains(fn (ClientRetainer $position): bool => $position->isActiveOn($first))) {
                    $eligible[] = $period;
                }
            }

            $active = collect($eligible)->first(fn (string $period): bool => ! isset($byPeriod[$period])) ?? $current;
            $states[$id] = new ClientSettlementState($baselines[$id], $current, $active, $eligible, $byPeriod);
        }

        return $states;
    }

    /**
     * Every period the client has figures for, newest first: closed months, months with a governing report, and the
     * active month. A month's report status and its settlement are separate facts on the row.
     *
     * @return list<HoursBankLedgerRow>
     */
    public function ledger(Client $client, ?CarbonInterface $now = null): array
    {
        [$state, $periods] = $this->ledgerPeriods($client, $now);

        return $this->ledgerRows($client, $state, $periods);
    }

    /**
     * One page of the ledger; only that page's months are read, so a long history costs no more per page. Without
     * $withIdleMonth the month in progress drops out when nothing settles in it: no retainer, report or closure.
     *
     * @return LengthAwarePaginator<int, HoursBankLedgerRow>
     */
    public function ledgerPage(Client $client, int $page = 1, int $perPage = 12, ?CarbonInterface $now = null, bool $withIdleMonth = true): LengthAwarePaginator
    {
        [$state, $periods] = $this->ledgerPeriods($client, $now);
        $active = $state->activePeriod;
        if (! $withIdleMonth && isset($periods[$active]) && $periods[$active]['report'] === null
            && $state->closureFor($active) === null && ! $state->isEligible($active)
            && ! $this->month($client, $active)->hasRetainer()) {
            unset($periods[$active]);
        }
        $slice = array_slice($periods, max(0, ($page - 1) * $perPage), $perPage, preserve_keys: true);

        return new LengthAwarePaginator($this->ledgerRows($client, $state, $slice), count($periods), $perPage, $page);
    }

    /** A closed month reads its frozen figures; logged time stays live, and the report link is whichever report governs now. */
    private static function fromClosure(string $period, ClientMonthClosure $closure, float $logged, ?ClientReport $report): HoursBankMonth
    {
        $snapshot = $closure->snapshot;
        $hours = fn (string $key): ?float => isset($snapshot[$key]) ? (float) $snapshot[$key] : null;

        return new HoursBankMonth(
            period: $period,
            contractedHours: $hours('contracted'),
            openingBalanceHours: (float) $hours('opening'),
            rolloverCapHours: $hours('cap'),
            usedHours: (float) $hours('used'),
            loggedHours: $logged,
            reportId: $report?->id,
            reportStatus: $report?->status,
            source: HoursBankMonth::SOURCE_CLOSURE,
            closureId: $closure->id,
            closedAt: CarbonImmutable::instance($closure->closed_at),
            outsideBank: (bool) ($snapshot['outside_bank'] ?? false),
        );
    }

    /**
     * @return array{ClientSettlementState, array<string, array{start: string, end: string, whole: bool, report: ?ClientReport}>}
     */
    private function ledgerPeriods(Client $client, ?CarbonInterface $now): array
    {
        $state = $this->settlement($client, $now);

        $reports = ClientReport::query()
            ->where('client_id', $client->id)
            ->get(['id', 'client_id', 'period_type', 'period_start', 'period_end', 'status', 'needs_recalculation', 'sent_at', 'finalized_at', 'contracted_hours', 'actual_hours', 'opening_balance_hours', 'rollover_cap_hours'])
            ->groupBy(fn (ClientReport $report): string => $report->period_end->toDateString())
            ->map(fn (Collection $rows): ?ClientReport => ClientReport::settling($rows, $state->baseline))
            ->filter();

        $periods = [];
        foreach ($reports as $end => $report) {
            $whole = $report->coversWholeMonth();
            $key = $whole ? $report->period_start->format('Y-m') : (string) $end;
            $periods[$key] = ['start' => $report->period_start->toDateString(), 'end' => (string) $end, 'whole' => $whole, 'report' => $report];
        }
        foreach ([...array_keys($state->closures), $state->activePeriod] as $period) {
            $first = LocalCalendar::monthFrom($period);
            $periods[$period] ??= ['start' => $first->toDateString(), 'end' => $first->endOfMonth()->toDateString(), 'whole' => true, 'report' => null];
        }

        uasort($periods, fn (array $a, array $b): int => [$b['end'], $b['start']] <=> [$a['end'], $a['start']]);

        return [$state, $periods];
    }

    /**
     * @param  array<string, array{start: string, end: string, whole: bool, report: ?ClientReport}>  $periods
     * @return list<HoursBankLedgerRow>
     */
    private function ledgerRows(Client $client, ClientSettlementState $state, array $periods): array
    {
        $baselineDay = LocalCalendar::monthFrom($state->baseline)->toDateString();
        $rows = [];
        foreach ($periods as $key => $row) {
            $rows[] = new HoursBankLedgerRow(
                period: mb_substr($row['start'], 0, 7),
                periodStart: $row['start'],
                periodEnd: $row['end'],
                wholeMonth: $row['whole'],
                month: $row['whole'] ? $this->month($client, (string) $key) : null,
                report: $row['report'],
                closure: $row['whole'] ? $state->closureFor((string) $key) : null,
                active: $row['whole'] && $key === $state->activePeriod,
                eligible: $row['whole'] && $state->isEligible((string) $key),
                beforeBaseline: $row['end'] < $baselineDay,
            );
        }

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, HoursBankMonth>
     */
    private function monthsIn(array $ids, string $period): array
    {
        $first = LocalCalendar::monthFrom($period);
        $firstDay = $first->toDateString();
        [$from, $to] = LocalCalendar::monthRange($period);

        $closures = ClientMonthClosure::query()->whereIn('client_id', $ids)->where('period', $period)->get()->keyBy('client_id');
        $reports = ClientReport::query()
            ->whereIn('client_id', $ids)
            ->where('period_type', ClientReport::PERIOD_MONTH)
            ->whereDate('period_start', $firstDay)
            ->whereDate('period_end', $first->endOfMonth()->toDateString())
            ->get()
            ->groupBy('client_id')
            ->map(fn (Collection $rows): ?ClientReport => ClientReport::governing($rows));
        $openings = $this->openingBalances($ids, $firstDay);

        $retainers = ClientRetainer::query()
            ->whereIn('client_id', $ids)
            ->leadingOn(Carbon::parse($firstDay))
            ->get()
            ->groupBy('client_id')
            ->map(fn (Collection $rows): ClientRetainer => $rows->first());

        $minutes = fn (bool $reportable): Collection => TimeEntry::query()
            ->whereIn('client_id', $ids)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->when($reportable, fn ($query) => $query->reportable())
            ->groupBy('client_id')
            ->selectRaw('client_id, SUM(duration_minutes) as minutes')
            ->pluck('minutes', 'client_id');
        $reportableMinutes = $minutes(true);
        $loggedMinutes = $minutes(false);

        $months = [];
        foreach ($ids as $id) {
            $logged = round((int) ($loggedMinutes[$id] ?? 0) / 60, 2);
            $reportable = round((int) ($reportableMinutes[$id] ?? 0) / 60, 2);
            $report = $reports->get($id);
            $closure = $closures->get($id);

            if ($closure !== null) {
                $months[$id] = self::fromClosure($period, $closure, $logged, $report);

                continue;
            }

            if ($report !== null) {
                // A draft for a month still running keeps its balance but not its hours, which would freeze at generation.
                $governs = $report->status !== ClientReport::STATUS_DRAFT || $report->period_end->lt(LocalCalendar::todayDate());
                $months[$id] = new HoursBankMonth(
                    period: $period,
                    contractedHours: $report->contracted_hours !== null ? (float) $report->contracted_hours : null,
                    openingBalanceHours: (float) $report->opening_balance_hours,
                    rolloverCapHours: $report->rollover_cap_hours !== null ? (float) $report->rollover_cap_hours : null,
                    usedHours: $governs ? (float) $report->actual_hours : $reportable,
                    loggedHours: $logged,
                    reportId: $report->id,
                    reportStatus: $report->status,
                    source: $governs ? HoursBankMonth::SOURCE_REPORT : HoursBankMonth::SOURCE_ENTRIES,
                    outsideBank: $report->contracted_hours === null,
                );

                continue;
            }

            $retainer = $retainers->get($id);
            $months[$id] = new HoursBankMonth(
                period: $period,
                contractedHours: $retainer !== null ? (float) $retainer->monthly_hours : null,
                openingBalanceHours: $openings[$id],
                rolloverCapHours: $retainer?->rollover_cap_hours !== null ? (float) $retainer->rollover_cap_hours : null,
                usedHours: $reportable,
                loggedHours: $logged,
                reportId: null,
                reportStatus: null,
            );
        }

        return $months;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, float> keyed by client id
     */
    private function openingBalances(array $ids, string $date): array
    {
        $month = mb_substr($date, 0, 7);
        $latestClosure = ClientMonthClosure::query()
            ->selectRaw('client_id, MAX(period) as latest_period')
            ->whereIn('client_id', $ids)
            ->where('period', '<', $month)
            ->groupBy('client_id');
        $closures = ClientMonthClosure::query()
            ->select('client_month_closures.*')
            ->joinSub($latestClosure, 'latest', fn ($join) => $join
                ->on('latest.client_id', '=', 'client_month_closures.client_id')
                ->on('latest.latest_period', '=', 'client_month_closures.period'))
            ->get()
            ->keyBy('client_id');

        $reports = $this->latestReportsBefore($ids, $date);

        $openings = [];
        foreach ($ids as $id) {
            $closure = $closures->get($id);
            $report = $reports->get($id);
            $closureEnd = $closure !== null ? LocalCalendar::monthFrom($closure->period)->endOfMonth()->toDateString() : null;

            $openings[$id] = match (true) {
                $closure !== null && ($report === null || $closureEnd >= $report->period_end->toDateString()) => $closure->closingHours(),
                $report !== null => $report->closingBalanceHours(),
                default => 0.0,
            };
        }

        return $openings;
    }

    /**
     * The governing report of the latest period ending before the date, loading only that period's rows. From the
     * baseline on only a whole month settles; a month-typed partial there sends the search one period further back.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ClientReport>
     */
    private function latestReportsBefore(array $ids, string $date): Collection
    {
        $baselines = $this->baselines($ids);
        $found = collect();
        $pending = collect($ids)->mapWithKeys(fn (int $id): array => [$id => $date]);

        while ($pending->isNotEmpty()) {
            $next = collect();
            foreach ($pending->groupBy(fn (string $before, int $id): string => $before.'|'.$baselines[$id], preserveKeys: true) as $key => $group) {
                [$before, $baseline] = explode('|', (string) $key);
                $baselineDay = LocalCalendar::monthFrom($baseline)->toDateString();
                $groupIds = array_map(intval(...), array_keys($group->all()));
                $latest = ClientReport::query()
                    ->selectRaw('client_id, MAX(period_end) as latest_end')
                    ->whereIn('client_id', $groupIds)
                    ->whereDate('period_end', '<', $before)
                    ->where(fn ($query) => $query->whereDate('period_end', '<', $baselineDay)->orWhere('period_type', ClientReport::PERIOD_MONTH))
                    ->groupBy('client_id');

                $rows = ClientReport::query()
                    ->select(self::BALANCE_COLUMNS)
                    ->joinSub($latest, 'latest', fn ($join) => $join
                        ->on('latest.client_id', '=', 'client_reports.client_id')
                        ->on('latest.latest_end', '=', 'client_reports.period_end'))
                    ->get()
                    ->groupBy('client_id');

                foreach ($rows as $id => $tied) {
                    $settling = ClientReport::settling($tied, $baselines[(int) $id]);
                    if ($settling !== null) {
                        $found[(int) $id] = $settling;
                    } else {
                        $next[(int) $id] = $tied->first()->period_end->toDateString();
                    }
                }
            }
            $pending = $next;
        }

        return $found;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> each client's account baseline month, keyed by client id
     */
    private function baselines(array $ids): array
    {
        $accounts = Client::withTrashed()->whereIn('id', $ids)->pluck('account_id', 'id');
        $baselines = Account::query()->whereIn('id', $accounts->unique()->values())->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->settlementFrom()]);

        return $accounts->map(fn (int|string $accountId): string => $baselines[(int) $accountId])->all();
    }

    /**
     * @param  iterable<Client>  $clients
     * @return list<int>
     */
    private function ids(iterable $clients): array
    {
        return collect($clients)->map(fn (Client $client): int => $client->id)->unique()->values()->all();
    }
}

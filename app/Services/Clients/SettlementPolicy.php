<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Support\LocalCalendar;

/**
 * The rules every change to a month's settled figures answers to, whoever makes it: a closure freezes its month and
 * everything before it, a finalized or sent later report freezes what it carried in, and recalculation runs oldest first.
 * Callers hold ClientFinancialLock.
 */
final class SettlementPolicy
{
    /**
     * A calendar month as the dates it runs from and to.
     *
     * @return array{string, string}
     */
    public static function monthDates(string $period): array
    {
        $first = LocalCalendar::monthFrom($period);

        return [$first->toDateString(), $first->endOfMonth()->toDateString()];
    }

    /**
     * How far each client's logged time is settled: through its latest closed month, since a closure freezes its
     * month and every one before it, and through the last day of its latest finalized or sent report.
     *
     * @param  list<int>  $clientIds
     * @return array<int, LoggedTimeFreeze> by client id, one for every id asked
     */
    public function loggedTimeFreezes(array $clientIds): array
    {
        $closed = ClientMonthClosure::query()
            ->whereIn('client_id', $clientIds)
            ->groupBy('client_id')
            ->selectRaw('client_id, MAX(period) AS period')
            ->pluck('period', 'client_id');
        $reports = ClientReport::query()
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', [ClientReport::STATUS_FINALIZED, ClientReport::STATUS_SENT])
            ->orderBy('period_end')
            ->get(['client_id', 'period_start', 'period_end'])
            ->keyBy('client_id');

        $freezes = [];
        foreach ($clientIds as $id) {
            $report = $reports->get($id);
            $freezes[$id] = new LoggedTimeFreeze(
                $closed->has($id) ? (string) $closed->get($id) : null,
                $report?->period_end->toDateString(),
                $report?->period_start->format('Y-m'),
            );
        }

        return $freezes;
    }

    public function closureFor(Client $client, string $period): ?ClientMonthClosure
    {
        return ClientMonthClosure::query()->where('client_id', $client->id)->where('period', $period)->first();
    }

    /** The report that settles the day $report ends on, as stored now. */
    public function settlingReport(Client $client, ClientReport $report): ?ClientReport
    {
        return ClientReport::settling(
            ClientReport::query()->where('client_id', $client->id)->whereDate('period_end', $report->period_end->toDateString())->get(),
            $client->account->settlementFrom(),
        );
    }

    /**
     * Why the figures of the period from $start to $end (dates) cannot change now, or null when they can. A closure
     * freezes its whole month and every period before it; a later report counts from the day after $end.
     */
    public function figureChangeRefusal(Client $client, string $start, string $end): ?string
    {
        $closure = ClientMonthClosure::query()
            ->where('client_id', $client->id)
            ->where('period', '>=', mb_substr($start, 0, 7))
            ->orderByDesc('period')
            ->first();
        if ($closure !== null) {
            return __('Reopen :month to change the figures.', ['month' => LocalCalendar::monthName($closure->period)]);
        }

        return $this->laterReportRefusal($client, $end);
    }

    /**
     * Why a report's figures for the period from $start to $end (dates) cannot change now: nothing at or after it may be
     * settled, and nothing before it may wait for recalculation. ReportWriter and the ledger's opening edit both ask this.
     */
    public function reportFigureRefusal(Client $client, string $start, string $end): ?string
    {
        return $this->earlierFlaggedRefusal($client, $start) ?? $this->figureChangeRefusal($client, $start, $end);
    }

    /** A finalized or sent report starting after $end (a date) has gone out with the balance carried into it. */
    public function laterReportRefusal(Client $client, string $end): ?string
    {
        return $this->refusalForLaterReport($this->laterSettledReport($client, $end));
    }

    /** The first finalized or sent report starting after $end (a date), the one a reopen or a figure change waits on. */
    public function laterSettledReport(Client $client, string $end): ?ClientReport
    {
        return ClientReport::query()
            ->where('client_id', $client->id)
            ->whereDate('period_start', '>', $end)
            ->whereIn('status', [ClientReport::STATUS_FINALIZED, ClientReport::STATUS_SENT])
            ->orderBy('period_start')
            ->first(['id', 'period_start']);
    }

    public function refusalForLaterReport(?ClientReport $report): ?string
    {
        return $report !== null
            ? __('The report for :month is already finalized or sent; reopen it first.', ['month' => LocalCalendar::monthName($report->period_start->format('Y-m'))])
            : null;
    }

    /** A report starting before $start (a date) still waits for recalculation, so nothing after it may consume its balance. */
    public function earlierFlaggedRefusal(Client $client, string $start): ?string
    {
        $flagged = ClientReport::query()
            ->where('client_id', $client->id)
            ->where('needs_recalculation', true)
            ->whereDate('period_start', '<', $start)
            ->orderBy('period_start')
            ->first(['id', 'period_start']);

        return $flagged !== null
            ? __('Recalculate the report for :month first.', ['month' => LocalCalendar::monthName($flagged->period_start->format('Y-m'))])
            : null;
    }

    /** The report itself, or one before it, waits for recalculation: it may not be finalized, sent or closed on. */
    public function recalculationRefusal(Client $client, ClientReport $report): ?string
    {
        if ($report->needs_recalculation) {
            return __('Regenerate this report with the opening balance recalculated first: an earlier month changed.');
        }

        return $this->earlierFlaggedRefusal($client, $report->period_start->toDateString());
    }

    /**
     * Every draft starting after $end (a date) carried in a balance that just changed; flagged, it waits for a
     * recalculating regenerate.
     *
     * @return list<string> the months of the periods flagged, oldest first
     */
    public function flagLaterDrafts(Client $client, string $end): array
    {
        $drafts = ClientReport::query()
            ->where('client_id', $client->id)
            ->where('status', ClientReport::STATUS_DRAFT)
            ->whereDate('period_start', '>', $end)
            ->orderBy('period_start')
            ->lockForUpdate()
            ->get(['id', 'period_start', 'needs_recalculation']);

        ClientReport::query()->whereKey($drafts->pluck('id'))->update(['needs_recalculation' => true]);

        return $drafts->map(fn (ClientReport $draft): string => $draft->period_start->format('Y-m'))->unique()->values()->all();
    }
}

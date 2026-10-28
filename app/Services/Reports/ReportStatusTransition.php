<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\ClientFinancialLock;
use App\Services\Clients\SettlementPolicy;
use App\Support\LocalCalendar;

/** The one path a report's status moves on: draft to finalized to sent, and back to draft. */
final class ReportStatusTransition
{
    public function __construct(
        private readonly ClientFinancialLock $lock,
        private readonly SettlementPolicy $policy,
    ) {}

    public function finalize(ClientReport $report, ?User $by = null): ReportTransitionResult
    {
        return $this->move($report, ClientReport::STATUS_FINALIZED, $by);
    }

    public function markSent(ClientReport $report, ?User $by = null): ReportTransitionResult
    {
        return $this->move($report, ClientReport::STATUS_SENT, $by);
    }

    public function reopen(ClientReport $report, ?User $by = null): ReportTransitionResult
    {
        return $this->move($report, ClientReport::STATUS_DRAFT, $by);
    }

    private static function reason(string $to): string
    {
        return match ($to) {
            ClientReport::STATUS_FINALIZED => ClientReportRevision::REASON_FINALIZE,
            ClientReport::STATUS_SENT => ClientReportRevision::REASON_SENT,
            default => ClientReportRevision::REASON_REOPEN,
        };
    }

    /** @return array<string, mixed> */
    private static function changes(ClientReport $report, string $to): array
    {
        return match ($to) {
            ClientReport::STATUS_FINALIZED => ['status' => $to, 'finalized_at' => now()],
            ClientReport::STATUS_SENT => ['status' => $to, 'sent_at' => $report->sent_at ?? now()],
            default => ['status' => $to, 'finalized_at' => null, 'sent_at' => null],
        };
    }

    private static function done(string $to): string
    {
        return match ($to) {
            ClientReport::STATUS_FINALIZED => __('Report finalized.'),
            ClientReport::STATUS_SENT => __('Report marked as sent.'),
            default => __('Report reopened as draft.'),
        };
    }

    private function move(ClientReport $report, string $to, ?User $by): ReportTransitionResult
    {
        return $this->lock->report($report, function (ClientReport $report, Client $client) use ($to, $by): ReportTransitionResult {
            $settled = $this->settled($report->status, $to);
            if ($settled !== null) {
                return new ReportTransitionResult($settled[0], $report, $settled[1]);
            }

            $refusal = $this->recalculationRefusal($report, $client, $to) ?? $this->runningTimerRefusal($report, $client, $to) ?? $this->governingRefusal($report, $client, $to);
            if ($refusal !== null) {
                return new ReportTransitionResult(ReportTransitionOutcome::Refused, $report, $refusal);
            }

            $before = $this->policy->settlingReport($client, $report)?->id;
            $report->recordRevision(self::reason($to), $by);
            $report->update(self::changes($report, $to));
            // Another report now settles the period, so every draft after it carried in a balance that moved.
            if ($this->policy->settlingReport($client, $report)?->id !== $before) {
                $this->policy->flagLaterDrafts($client, $report->period_end->toDateString());
            }

            return new ReportTransitionResult(ReportTransitionOutcome::Applied, $report, self::done($to));
        });
    }

    /** A report waiting for recalculation, or after one that is, prints a balance that may be stale: it is not finalized or sent. */
    private function recalculationRefusal(ClientReport $report, Client $client, string $to): ?string
    {
        return $to === ClientReport::STATUS_DRAFT ? null : $this->policy->recalculationRefusal($client, $report);
    }

    /** A finalized or sent report freezes logged time up to its end, so a timer still running there would change it on stop. */
    private function runningTimerRefusal(ClientReport $report, Client $client, string $to): ?string
    {
        if ($to === ClientReport::STATUS_DRAFT) {
            return null;
        }
        $running = TimeEntry::query()
            ->where('client_id', $client->id)
            ->whereNull('end_time')
            ->where('start_time', '<', LocalCalendar::dayRange($report->period_end)[1])
            ->exists();

        return $running ? __('Stop the timer running in this report\'s period first.') : null;
    }

    /**
     * Where two reports end on the same day the status decides which one the hours bank reads, so a status move that
     * hands the period to the other report changes its settled figures and answers to the same rules as a regenerate.
     */
    private function governingRefusal(ClientReport $report, Client $client, string $to): ?string
    {
        $tied = ClientReport::query()
            ->where('client_id', $client->id)
            ->whereDate('period_end', $report->period_end->toDateString())
            ->get();
        if ($tied->count() < 2) {
            return null;
        }

        $baseline = $client->account->settlementFrom();
        $before = $this->policy->settlingReport($client, $report)?->id;
        $after = ClientReport::settling($tied->map(fn (ClientReport $row): ClientReport => $row->id === $report->id ? (clone $row)->forceFill(['status' => $to]) : $row), $baseline)?->id;

        return $before !== $after ? $this->policy->figureChangeRefusal($client, $report->period_start->toDateString(), $report->period_end->toDateString()) : null;
    }

    /**
     * The answer when the status machine itself decides, before any refusal: a no-op or a refusal; null lets the move through.
     *
     * @return array{ReportTransitionOutcome, string}|null
     */
    private function settled(string $from, string $to): ?array
    {
        $unchanged = ReportTransitionOutcome::Unchanged;

        return match ([$from, $to]) {
            [ClientReport::STATUS_DRAFT, ClientReport::STATUS_DRAFT] => [$unchanged, __('The report is already a draft.')],
            [ClientReport::STATUS_FINALIZED, ClientReport::STATUS_FINALIZED] => [$unchanged, __('The report is already finalized.')],
            [ClientReport::STATUS_SENT, ClientReport::STATUS_FINALIZED] => [$unchanged, __('The report has already been sent, so it stays sent.')],
            [ClientReport::STATUS_SENT, ClientReport::STATUS_SENT] => [$unchanged, __('The report is already marked as sent.')],
            [ClientReport::STATUS_DRAFT, ClientReport::STATUS_SENT] => [ReportTransitionOutcome::Refused, __('Finalize the report first.')],
            default => null,
        };
    }
}

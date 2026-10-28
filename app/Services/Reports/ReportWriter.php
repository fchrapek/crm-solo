<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Models\User;
use App\Services\Clients\ClientFinancialLock;
use App\Services\Clients\SettlementPolicy;
use App\Services\Humanizer;
use App\Support\LocalCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Every write that changes a report's figures or body, for the web and the CLI alike. Composing may take an AI call,
 * so it runs before the client lock; under the lock the settlement rules and the figures are checked again, and a write
 * whose inputs moved meanwhile is refused rather than saved.
 */
final class ReportWriter
{
    public function __construct(
        private readonly ClientFinancialLock $lock,
        private readonly ReportDataAggregator $aggregator,
        private readonly ReportComposerRegistry $composers,
        private readonly SettlementPolicy $policy,
    ) {}

    /** A new draft for a whole month; a closed month is generated from its closure's figures. */
    public function generate(Client $client, Carbon $start, Carbon $end, string $locale, ?string $composerKey = null, ?float $hours = null): ReportWriteResult
    {
        $existing = ClientReport::coveringPeriod($client->id, $start->toDateString(), $end->toDateString());
        if ($existing !== null) {
            return new ReportWriteResult(ReportWriteResult::EXISTS, '', $existing);
        }

        $period = $start->format('Y-m');
        $refusal = $this->generateRefusal($client, $period, $hours);
        if ($refusal !== null) {
            return ReportWriteResult::refused($refusal);
        }

        $composer = $composerKey !== null ? $this->composers->get($composerKey) : $this->composers->default();
        $context = $this->context($client, $start, $end, ClientReport::PERIOD_MONTH, $locale, null, $hours);
        $body = $composer->compose($context);

        return $this->lock->client($client->id, function (Client $client) use ($start, $end, $period, $locale, $hours, $context, $composer, $body): ReportWriteResult {
            $existing = ClientReport::coveringPeriod($client->id, $start->toDateString(), $end->toDateString());
            if ($existing !== null) {
                return new ReportWriteResult(ReportWriteResult::EXISTS, '', $existing);
            }

            $refusal = $this->generateRefusal($client, $period, $hours);
            if ($refusal !== null) {
                return ReportWriteResult::refused($refusal);
            }
            if ($this->context($client, $start, $end, ClientReport::PERIOD_MONTH, $locale, null, $hours)->figures() !== $context->figures()) {
                return ReportWriteResult::refused(self::figuresMoved());
            }

            $report = $client->reports()->create([
                'account_id' => $client->account_id,
                'period_type' => ClientReport::PERIOD_MONTH,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'contracted_hours' => $context->contractedHours(),
                'actual_hours' => $context->actualHours,
                // Snapshotted, so a later retainer edit never rewrites what this period promised.
                'opening_balance_hours' => $context->openingBalanceHours,
                'rollover_cap_hours' => $context->rolloverCapHours,
                'currency' => $context->currency(),
                'composer_key' => $composer->key(),
                'body_markdown' => $body,
                'status' => ClientReport::STATUS_DRAFT,
                'generated_at' => now(),
            ]);
            if ($context->closure === null) {
                $this->policy->flagLaterDrafts($client, $end->toDateString());
            }

            return new ReportWriteResult(ReportWriteResult::APPLIED, __('Draft report generated.'), $report);
        });
    }

    /**
     * Recomposes the body and refreshes the figures. The saved opening balance survives unless recalculation is asked
     * for, or the report is flagged: then the balance is carried in again from the settled month before it.
     */
    public function regenerate(ClientReport $report, string $locale, ?string $composerKey = null, bool $recalculate = false, ?User $by = null): ReportWriteResult
    {
        $client = Client::withTrashed()->findOrFail($report->client_id);
        $refusal = $this->figureRefusal($client, $report);
        if ($refusal !== null) {
            return ReportWriteResult::refused($refusal, $report);
        }

        $recalculate = $recalculate || $report->needs_recalculation;
        $opening = $report->opening_balance_hours !== null && ! $recalculate ? (float) $report->opening_balance_hours : null;
        // A hand-written report carries a key no composer backs ('manual'), so fall back to the default.
        $composer = $composerKey !== null ? $this->composers->get($composerKey) : $this->composers->getOrDefault($report->composer_key);
        $version = $report->version();
        $status = $report->status;
        $flagged = (bool) $report->needs_recalculation;

        $context = $this->context($client, $report->period_start, $report->period_end, (string) $report->period_type, $locale, $opening);
        $body = $composer->compose($context);

        return $this->lock->report($report, function (ClientReport $report, Client $client) use ($locale, $opening, $composer, $body, $context, $version, $status, $flagged, $by): ReportWriteResult {
            if ($report->version() !== $version || $report->status !== $status) {
                return ReportWriteResult::refused(__('The report changed while it was being regenerated, so nothing was replaced. Reload the page and regenerate again.'), $report);
            }

            $refusal = $this->figureRefusal($client, $report);
            if ($refusal !== null) {
                return ReportWriteResult::refused($refusal, $report);
            }
            if ((bool) $report->needs_recalculation !== $flagged) {
                return ReportWriteResult::refused(self::figuresMoved(), $report);
            }
            if ($this->context($client, $report->period_start, $report->period_end, (string) $report->period_type, $locale, $opening)->figures() !== $context->figures()) {
                return ReportWriteResult::refused(self::figuresMoved(), $report);
            }

            $report->recordRevision(ClientReportRevision::REASON_REGENERATE, $by);
            $report->update([
                'composer_key' => $composer->key(),
                'body_markdown' => $body,
                'contracted_hours' => $context->contractedHours(),
                'actual_hours' => $context->actualHours,
                'opening_balance_hours' => $context->openingBalanceHours,
                'rollover_cap_hours' => $context->rolloverCapHours,
                'currency' => $context->currency(),
                'generated_at' => now(),
                'needs_recalculation' => false,
            ]);
            $this->policy->flagLaterDrafts($client, $report->period_end->toDateString());

            return new ReportWriteResult(ReportWriteResult::APPLIED, __('Report regenerated.'), $report);
        });
    }

    /**
     * Saves an edited body. On a closed month the billing summary is re-rendered from the closure, so the printed
     * figures cannot drift from the frozen ones; a body without exactly one summary is rejected there.
     *
     * @throws ValidationException
     */
    public function updateBody(ClientReport $report, string $body, string $version, ?User $by = null): ReportWriteResult
    {
        return $this->lock->report($report, function (ClientReport $report, Client $client) use ($body, $version, $by): ReportWriteResult {
            if ($report->version() !== $version) {
                return ReportWriteResult::refused(self::staleEdit(), $report);
            }

            $closure = $report->coversWholeMonth() ? $this->policy->closureFor($client, $report->period_start->format('Y-m')) : null;
            if ($closure !== null && ($figures = ClosureFigures::of($closure))->contracted !== null) {
                $summary = BillingSummary::replaceIn($body, $figures->opening, $figures->contracted, $figures->used, $figures->cap, ClientReport::PERIOD_MONTH);
                if ($summary['status'] !== BillingSummary::REWRITTEN) {
                    throw ValidationException::withMessages(['body_markdown' => __('This month is closed, so the report keeps exactly one billing summary: its figures come from the closure.')]);
                }
                $body = $summary['body'];
            }

            // No-op when the body is unchanged, so no spurious revision row.
            if (Humanizer::clean($body) === $report->body_markdown) {
                return new ReportWriteResult(ReportWriteResult::UNCHANGED, '', $report);
            }

            $report->recordRevision(ClientReportRevision::REASON_UPDATE, $by);
            $report->update(['body_markdown' => $body]);

            return new ReportWriteResult(ReportWriteResult::APPLIED, __('Report saved.'), $report);
        });
    }

    /**
     * Sets the opening balance carried into the period and re-renders the body's billing summary from it in the same
     * write, in the editor's unsaved body when one is sent.
     */
    public function updateOpening(ClientReport $report, ?float $opening, ?string $body, ?string $version, ?User $by = null): ReportWriteResult
    {
        return $this->lock->report($report, function (ClientReport $report, Client $client) use ($opening, $body, $version, $by): ReportWriteResult {
            if ($version !== null && $report->version() !== $version) {
                return ReportWriteResult::refused(self::staleEdit(), $report);
            }

            $refusal = $this->figureRefusal($client, $report);
            if ($refusal !== null) {
                return ReportWriteResult::refused($refusal, $report);
            }

            $body ??= (string) $report->body_markdown;
            // A report with no retainer prints no balance, so there is nothing to re-render.
            $summary = $report->contracted_hours === null
                ? ['status' => BillingSummary::REWRITTEN, 'body' => $body]
                : BillingSummary::replaceIn(
                    $body,
                    (float) $opening,
                    (float) $report->contracted_hours,
                    (float) $report->actual_hours,
                    $report->rollover_cap_hours !== null ? (float) $report->rollover_cap_hours : null,
                    (string) ($report->period_type ?? ClientReport::PERIOD_MONTH),
                );
            $newBody = Humanizer::clean($summary['body']);

            $current = $report->opening_balance_hours !== null ? (float) $report->opening_balance_hours : null;
            if ($opening === $current && $newBody === $report->body_markdown) {
                return new ReportWriteResult(ReportWriteResult::UNCHANGED, '', $report);
            }

            $report->recordRevision(ClientReportRevision::REASON_OPENING_BALANCE, $by);
            $report->update([
                'opening_balance_hours' => $opening,
                'body_markdown' => $newBody,
            ]);
            if ($opening !== $current) {
                $this->policy->flagLaterDrafts($client, $report->period_end->toDateString());
            }

            return new ReportWriteResult(ReportWriteResult::APPLIED, match ($summary['status']) {
                BillingSummary::MISSING => __('Opening balance saved. The report has no billing summary, so its text was left as is.'),
                BillingSummary::AMBIGUOUS => __('Opening balance saved. The report has more than one billing summary, so its text was left as is: check the balances in it by hand.'),
                default => __('Opening balance saved.'),
            }, $report);
        });
    }

    public function destroy(ClientReport $report): ReportWriteResult
    {
        return $this->lock->report($report, function (ClientReport $report, Client $client): ReportWriteResult {
            $refusal = $this->figureRefusal($client, $report);
            if ($refusal !== null) {
                return ReportWriteResult::refused($refusal, $report);
            }

            $report->delete();
            $this->policy->flagLaterDrafts($client, $report->period_end->toDateString());

            return new ReportWriteResult(ReportWriteResult::APPLIED, __('Report deleted.'));
        });
    }

    private static function figuresMoved(): string
    {
        return __('The figures changed, try again.');
    }

    private static function staleEdit(): string
    {
        return __('The report changed since you opened it, so your edit was not saved. Copy your text, reload the page and apply it again.');
    }

    private function figureRefusal(Client $client, ClientReport $report): ?string
    {
        return $this->policy->reportFigureRefusal($client, $report->period_start->toDateString(), $report->period_end->toDateString());
    }

    /** A closed month may still get its report, from the closure's figures, but never at an overridden hour count. */
    private function generateRefusal(Client $client, string $period, ?float $hours): ?string
    {
        [$start, $end] = SettlementPolicy::monthDates($period);
        $flagged = $this->policy->earlierFlaggedRefusal($client, $start);
        if ($flagged !== null) {
            return $flagged;
        }

        if ($this->policy->closureFor($client, $period) !== null) {
            return $hours !== null ? __('Reopen :month to change the figures.', ['month' => LocalCalendar::monthName($period)]) : null;
        }

        return $this->policy->figureChangeRefusal($client, $start, $end);
    }

    private function context(Client $client, Carbon $start, Carbon $end, string $type, string $locale, ?float $opening, ?float $hours = null): ReportContext
    {
        $closure = $type === ClientReport::PERIOD_MONTH && $start->day === 1 && $end->toDateString() === $start->copy()->endOfMonth()->toDateString()
            ? $this->policy->closureFor($client, $start->format('Y-m'))
            : null;

        $context = $this->aggregator->aggregate($client, $start, $end, $type, $locale, $opening, $closure !== null ? ClosureFigures::of($closure) : null);

        return $hours !== null && $closure === null ? $context->withActualHours($hours) : $context;
    }
}

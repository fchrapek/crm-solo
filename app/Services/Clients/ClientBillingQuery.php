<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Invoice;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Services\Integrations\InfaktDrafting;
use App\Services\Messaging\SummaryEmails;
use App\Services\Reports\BaselineSections;
use App\Services\Reports\ReportComposerRegistry;
use App\Support\LocalCalendar;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\MergeProp;

final class ClientBillingQuery
{
    public const int LEDGER_ROWS = 12;

    public const int INVOICE_ROWS = 5;

    public const int ORDER_MONTHS = 6;

    public const int ORDER_REPORTS = 12;

    public function __construct(
        private readonly HoursBank $bank,
        private readonly SettlementPolicy $policy,
        private readonly ClientBillingMode $mode,
        private readonly ClientOrderSummary $order,
        private readonly InfaktDrafting $drafting,
        private readonly BilledTime $billed,
        private readonly ClientInvoiceList $invoiceList,
        private readonly ReportComposerRegistry $composers,
        private readonly BaselineSections $baseline,
    ) {}

    /** The month in the URL when it is a month up to the one in progress, else the month the client owes next. */
    public static function shownPeriod(?string $month, ClientSettlementState $state): string
    {
        return $month !== null && LocalCalendar::isMonth($month) && $month <= $state->currentPeriod ? $month : $state->activePeriod;
    }

    /**
     * The ledger pages by Older: a partial reload of billing_ledger with the next page, whose rows the client appends.
     *
     * @param  string|null  $ledgerPage  the `next` of the ledger page shown last
     * @param  string|null  $mode  the billing mode when the caller already read it
     * @param  string|null  $month  the month the screen shows (YYYY-MM); the active month when missing or not a month up to now
     * @param  string|null  $invoiceYear  the invoice list's year, `all` or `last12`
     * @param  string|null  $invoiceMonth  the invoice list's month within its year
     * @return array{billing: Closure, billing_invoices: Closure, billing_ledger?: MergeProp, billing_emails?: Closure, billing_month?: Closure}
     */
    public function props(Client $client, ?string $ledgerPage = null, ?string $mode = null, ?string $month = null, ?string $invoiceYear = null, ?string $invoiceMonth = null): array
    {
        $invoices = fn (): array => $this->invoiceList->props($client, $invoiceYear, $invoiceMonth);

        if (($mode ?? $this->mode->of($client)) === ClientBillingMode::ORDER) {
            return [
                'billing' => fn (): array => [
                    'mode' => ClientBillingMode::ORDER,
                    'billed_months' => $this->billedMonths($client),
                    'invoices' => $this->invoices($client),
                    'order' => $this->order->props($client),
                    'reports' => $this->reportList($client),
                    'report_period' => LocalCalendar::previousMonth(),
                    'composers' => $this->composerOptions(),
                    'currency' => $client->currency ?? 'PLN',
                    'report_baseline' => $this->reportBaseline($client),
                ],
                'billing_invoices' => $invoices,
            ];
        }

        $state = $this->bank->settlement($client);
        $page = $ledgerPage !== null && preg_match('/^[1-9]\d{0,3}$/', $ledgerPage) === 1 ? (int) $ledgerPage : 1;

        return [
            'billing' => fn (): array => $this->summary($client, $state),
            'billing_ledger' => Inertia::merge(fn (): array => $this->ledger($client, $state, $page))->append('rows', 'key'),
            'billing_emails' => fn (): ?array => app(SummaryEmails::class)->monthsFor($client, Auth::user()),
            'billing_month' => fn (): array => $this->month($client, self::shownPeriod($month, $state), $state),
            'billing_invoices' => $invoices,
        ];
    }

    /**
     * Positions in force today, the one a report would snapshot first.
     *
     * @return array<string, mixed>
     */
    public function retainer(Client $client): array
    {
        $all = $client->retainers()->get();
        $active = $client->retainers()->leadingOn(LocalCalendar::todayDate())->get();

        return [
            'active' => $active->map(fn (ClientRetainer $retainer): array => [
                'id' => $retainer->id,
                'label' => $retainer->label,
                'monthly_hours' => (float) $retainer->monthly_hours,
                'monthly_fee' => $retainer->monthly_fee !== null ? (float) $retainer->monthly_fee : null,
                'rollover_cap_hours' => $retainer->rollover_cap_hours !== null ? (float) $retainer->rollover_cap_hours : null,
                'currency' => $retainer->currency,
            ])->values()->all(),
            'positions' => $all->map(fn (ClientRetainer $retainer): array => $retainer->toPayload())->values()->all(),
            'active_ids' => $active->pluck('id')->values()->all(),
            'currency' => $client->currency ?? 'PLN',
        ];
    }

    /**
     * One month as the screen shows it: both values per task (what the billed time dialog reads), its report, the
     * invoices booked in it and its checklist.
     *
     * @return array<string, mixed>
     */
    private function month(Client $client, string $period, ClientSettlementState $state): array
    {
        [$start] = SettlementPolicy::monthDates($period);
        $today = LocalCalendar::todayDate();
        $report = $this->billed->governingReport($client, $period);

        return [
            'period' => $period,
            'figures' => $this->billed->figures($client, $period),
            'settlement' => $this->monthSettlement($client, $period, $state),
            'report' => $report !== null ? [
                'id' => $report->id,
                'status' => $report->status,
                'sent_on' => $report->sentOn(),
                'generated_on' => $report->generated_at?->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
                'needs_recalculation' => (bool) $report->needs_recalculation,
                'composer_key' => $report->composer_key,
            ] : null,
            'invoices' => $client->invoices()
                ->where(DB::raw(Invoice::ACCRUAL_DATE_SQL), '>=', $start)
                // Up to the next month's first day: a date stored with a time still falls inside its month.
                ->where(DB::raw(Invoice::ACCRUAL_DATE_SQL), '<', LocalCalendar::nextMonth($period).'-01')
                ->reorder()
                ->orderByDesc('id')
                ->get()
                ->map(fn (Invoice $invoice): array => ClientInvoiceList::row($invoice, $today))
                ->values()
                ->all(),
            'checklist' => $this->checklist($client, $period),
            'composers' => $this->composerOptions(),
        ];
    }

    /**
     * The shown month's own settlement, whichever ledger page holds it: closed, owing a close, or neither, and the
     * closure to reopen when it is the latest one.
     *
     * @return array{state: 'closed'|'open'|null, closure_id: int|null, reopen_refusal: string|null, reopen_blocker: array{report_id: int, period: string}|null}
     */
    private function monthSettlement(Client $client, string $period, ClientSettlementState $state): array
    {
        $closure = $state->closureFor($period);

        return [
            'state' => $closure !== null ? 'closed' : ($period === $state->activePeriod || $state->isEligible($period) ? 'open' : null),
            ...$this->reopen($client, $closure, $state->latestClosure(), SettlementPolicy::monthDates($period)[1]),
        ];
    }

    /**
     * The closure to reopen, set only when it is the latest one, with what a later settled report says against it.
     *
     * @return array{closure_id: int|null, reopen_refusal: string|null, reopen_blocker: array{report_id: int, period: string}|null}
     */
    private function reopen(Client $client, ?ClientMonthClosure $closure, ?ClientMonthClosure $latest, string $end): array
    {
        $reopenable = $closure !== null && $latest !== null && $closure->id === $latest->id;
        $blocker = $reopenable ? $this->policy->laterSettledReport($client, $end) : null;

        return [
            'closure_id' => $reopenable ? $closure->id : null,
            'reopen_refusal' => $this->policy->refusalForLaterReport($blocker),
            // The report the refusal names, so the phone's blocked sheet can open it.
            'reopen_blocker' => $blocker !== null ? ['report_id' => $blocker->id, 'period' => $blocker->period_start->format('Y-m')] : null,
        ];
    }

    /**
     * The month's close checklist: its run once started, else what starting last month's would seed.
     *
     * @return array{done: int, total: int}|null
     */
    private function checklist(Client $client, string $period): ?array
    {
        $run = $client->monthCloseRuns()
            ->reorder()
            ->where('period', $period)
            ->withCount(['steps', 'steps as resolved_count' => fn ($q) => $q->where('state', '!=', MonthCloseStep::STATE_PENDING)])
            ->first();
        if ($run !== null) {
            return ['done' => (int) $run->resolved_count, 'total' => (int) $run->steps_count];
        }
        if ($period !== LocalCalendar::previousMonth() || $client->month_close_type === null || ! $client->include_in_month_close) {
            return null;
        }

        return ['done' => 0, 'total' => count(MonthCloseRun::stepTemplateFor($client))];
    }

    /** @return list<array{key: string, label: string}> */
    private function composerOptions(): array
    {
        return collect($this->composers->all())->map(fn ($composer): array => ['key' => $composer->key(), 'label' => $composer->label()])->values()->all();
    }

    /**
     * A client without a retainer has no month tiles to hold a report, so its reports are a list, newest first.
     *
     * @return list<array{id: int, period: string, status: string}>
     */
    private function reportList(Client $client): array
    {
        return $client->reports()
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(self::ORDER_REPORTS)
            ->get(['id', 'client_id', 'period_start', 'status'])
            ->map(fn (ClientReport $report): array => [
                'id' => $report->id,
                'period' => $report->period_start->format('Y-m'),
                'status' => $report->status,
            ])
            ->all();
    }

    /**
     * A client without a retainer has no ledger, so its months in both values stand alone: the last ORDER_MONTHS with
     * time or a report, newest first.
     *
     * @return list<array{period: string, billed: array<string, mixed>, billed_editable: bool}>
     */
    private function billedMonths(Client $client): array
    {
        $current = LocalCalendar::currentMonth();
        $periods = LocalCalendar::monthsBetween(LocalCalendar::monthFrom($current)->subMonthsNoOverflow(self::ORDER_MONTHS - 1)->format('Y-m'), LocalCalendar::nextMonth($current));
        $rows = [];
        foreach (array_reverse($periods) as $period) {
            $month = $this->bank->month($client, $period);
            $billed = $month->billed;
            if ($billed === null || ($billed->realMinutes === 0 && $billed->tasks === [] && $month->reportId === null)) {
                continue;
            }
            $rows[] = [
                'period' => $period,
                'billed' => $billed->toArray(),
                'billed_editable' => ! $month->isClosed() && in_array($month->reportStatus, [null, ClientReport::STATUS_DRAFT], true),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function summary(Client $client, ClientSettlementState $state): array
    {
        $reports = $this->settlingReports($client, $state->baseline);
        $newestReport = $reports->first();
        if ($newestReport !== null) {
            // The edit version hashes the body with the opening read here, so a change between the reads is refused.
            $newestReport->setRawAttributes([...$newestReport->getAttributes(), 'body_markdown' => ClientReport::query()->whereKey($newestReport->id)->value('body_markdown')]);
        }

        return [
            'mode' => ClientBillingMode::RETAINER,
            'cap' => $this->bank->month($client, LocalCalendar::currentMonth())->rolloverCapHours,
            'settlement' => [
                'active_period' => $state->activePeriod,
                'current_period' => $state->currentPeriod,
                'open_behind' => $state->openBehind(),
            ],
            // The newest period is what the bank carries from, so its opening balance is the one to correct, while the rules let it change.
            'opening_balance' => $newestReport !== null && $this->policy->reportFigureRefusal($client, $newestReport->period_start->toDateString(), $newestReport->period_end->toDateString()) === null ? [
                'report_id' => $newestReport->id,
                'value' => $newestReport->opening_balance_hours !== null ? (float) $newestReport->opening_balance_hours : null,
                // From the same read as the value and sent back with the change, so an edit made since is refused.
                'version' => $newestReport->version(),
            ] : null,
            'waiting_report' => $this->waitingReport($reports),
            'months' => $this->bank->ledgerMonths($client),
            'retainer' => $this->retainer($client),
            'invoices' => $this->invoices($client),
            'invoice_drafts' => $this->drafting->monthState($client),
            'report_baseline' => $this->reportBaseline($client),
        ];
    }

    /**
     * Report baseline with its sections, while the account has it on.
     *
     * @return array{markdown: string|null, sections: list<array{title: string|null, items: int}>}|null
     */
    private function reportBaseline(Client $client): ?array
    {
        if (! $client->account->showsReportBaseline()) {
            return null;
        }
        $markdown = $client->report_baseline_markdown;

        return ['markdown' => $markdown, 'sections' => $this->baseline->of($markdown)];
    }

    /**
     * One page of months, figures read for that page only; the entry row closes the last page.
     *
     * @return array{rows: list<array<string, mixed>>, next: string|null, start: array{date: string, balance: float}|null}
     */
    private function ledger(Client $client, ClientSettlementState $state, int $page): array
    {
        // A client without a retainer starts empty: its month in progress has nothing to settle.
        $paged = $this->bank->ledgerPage($client, $page, self::LEDGER_ROWS, withIdleMonth: false);
        $rows = $paged->items();
        $earliest = ! $paged->hasMorePages() && $rows !== [] ? $rows[count($rows) - 1] : null;
        $latestClosure = $state->latestClosure();

        return [
            'rows' => array_map(fn (HoursBankLedgerRow $row): array => $this->row($client, $row, $state, $latestClosure), $rows),
            'next' => $paged->hasMorePages() ? (string) ($page + 1) : null,
            'start' => $earliest !== null ? [
                'date' => $earliest->periodStart,
                'balance' => $earliest->month?->openingBalanceHours ?? (float) $earliest->report?->opening_balance_hours,
            ] : null,
        ];
    }

    /**
     * Settlement and report delivery are separate facts: "closed" only ever comes from a closure, "open" from a month that
     * owes a close (or is the one in progress), and a month before the baseline carries only its report's status.
     *
     * @return array<string, mixed>
     */
    private function row(Client $client, HoursBankLedgerRow $row, ClientSettlementState $state, ?ClientMonthClosure $latestClosure): array
    {
        $report = $row->report;
        $closure = $row->closure;
        $settlement = match (true) {
            $closure !== null => 'closed',
            $row->active || $row->eligible => 'open',
            default => null,
        };

        return [
            'key' => $row->periodStart.'/'.$row->periodEnd,
            'report_id' => $report?->id,
            'period' => $row->period,
            'period_start' => $row->periodStart,
            'period_end' => $row->periodEnd,
            'whole_month' => $row->wholeMonth,
            'ended' => $row->periodEnd < LocalCalendar::todayDate()->toDateString(),
            ...HoursBankLedgerRow::figures($row->month, $report),
            'status' => $report?->status,
            'sent_on' => $report?->sentOn(),
            'settlement' => $settlement,
            'active' => $row->active,
            'before_baseline' => $row->beforeBaseline,
            'closed_on' => $closure?->closed_at->copy()->setTimezone(LocalCalendar::timezone())->toDateString(),
            ...$this->reopen($client, $closure, $latestClosure, $row->periodEnd),
            'billed' => $row->month?->billed?->toArray(),
            // The pencil: a month whose billed figure may still change, before the dialog reads the full rules.
            'billed_editable' => $row->month?->billed !== null && $closure === null && ($report === null || $report->status === ClientReport::STATUS_DRAFT),
        ];
    }

    /**
     * The report settling each period end, newest first, without the figures the ledger page reads.
     *
     * @return Collection<int, ClientReport>
     */
    private function settlingReports(Client $client, string $baseline): Collection
    {
        return ClientReport::query()
            ->where('client_id', $client->id)
            ->get(['id', 'client_id', 'period_type', 'period_start', 'period_end', 'status', 'opening_balance_hours'])
            ->groupBy(fn (ClientReport $report): string => $report->period_end->toDateString())
            ->map(fn (Collection $rows): ?ClientReport => ClientReport::settling($rows, $baseline))
            ->filter()
            ->sortByDesc(fn (ClientReport $report): string => $report->period_end->toDateString())
            ->values();
    }

    /**
     * The oldest ended period whose report has not gone out, also when it is older than the ledger shows.
     *
     * @param  Collection<int, ClientReport>  $reports  newest first
     * @return array{report_id: int, period: string, status: string}|null
     */
    private function waitingReport(Collection $reports): ?array
    {
        $today = LocalCalendar::todayDate();
        $report = $reports->reverse()->first(fn (ClientReport $report): bool => $report->period_end->lt($today) && $report->status !== ClientReport::STATUS_SENT);

        return $report !== null ? ['report_id' => $report->id, 'period' => $report->period_start->format('Y-m'), 'status' => $report->status] : null;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, more: int}
     */
    private function invoices(Client $client): array
    {
        $today = LocalCalendar::todayDate();
        $total = $client->invoices()->count();

        $rows = $client->invoices()
            ->orderByDesc('id')
            ->limit(self::INVOICE_ROWS)
            ->get()
            ->map(fn (Invoice $invoice): array => ClientInvoiceList::row($invoice, $today))
            ->values()
            ->all();

        return ['rows' => $rows, 'more' => max(0, $total - count($rows))];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Invoice;
use App\Support\LocalCalendar;
use Closure;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\MergeProp;

final class ClientBillingQuery
{
    public const int LEDGER_ROWS = 12;

    public const int INVOICE_ROWS = 5;

    public function __construct(
        private readonly HoursBank $bank,
        private readonly SettlementPolicy $policy,
        private readonly ClientBillingMode $mode,
        private readonly ClientOrderSummary $order,
    ) {}

    /**
     * The ledger pages by "Starsze": a partial reload of billing_ledger with the next page, whose rows the client appends.
     *
     * @param  string|null  $ledgerPage  the `next` of the ledger page shown last
     * @param  string|null  $mode  the billing mode when the caller already read it
     * @return array{billing: Closure, billing_ledger?: MergeProp}
     */
    public function props(Client $client, ?string $ledgerPage = null, ?string $mode = null): array
    {
        if (($mode ?? $this->mode->of($client)) === ClientBillingMode::ORDER) {
            return ['billing' => fn (): array => [
                'mode' => ClientBillingMode::ORDER,
                'invoices' => $this->invoices($client),
                'order' => $this->order->props($client),
            ]];
        }

        $state = $this->bank->settlement($client);
        $page = $ledgerPage !== null && preg_match('/^[1-9]\d{0,3}$/', $ledgerPage) === 1 ? (int) $ledgerPage : 1;

        return [
            'billing' => fn (): array => $this->summary($client, $state),
            'billing_ledger' => Inertia::merge(fn (): array => $this->ledger($client, $state, $page))->append('rows', 'key'),
        ];
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
            'retainer' => $this->retainer($client),
            'invoices' => $this->invoices($client),
        ];
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
        $reopenable = $closure !== null && $latestClosure !== null && $closure->id === $latestClosure->id;
        $blocker = $reopenable ? $this->policy->laterSettledReport($client, $row->periodEnd) : null;

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
            'closure_id' => $reopenable ? $closure->id : null,
            'reopen_refusal' => $this->policy->refusalForLaterReport($blocker),
            // The report the refusal names, so the phone's blocked sheet can open it.
            'reopen_blocker' => $blocker !== null ? ['report_id' => $blocker->id, 'period' => $blocker->period_start->format('Y-m')] : null,
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
     * Positions in force today, the one a report would snapshot first.
     *
     * @return array<string, mixed>
     */
    private function retainer(Client $client): array
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
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'currency' => $invoice->currency ?? 'PLN',
                'net' => (float) ($invoice->net_price / 100),
                'gross' => (float) ($invoice->gross_price / 100),
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'sale_date' => $invoice->sale_date?->toDateString(),
                'payment_date' => $invoice->payment_date?->toDateString(),
                'paid_date' => $invoice->paid_date?->toDateString(),
                'overdue' => $invoice->isOverdueOn($today),
                'external_url' => $invoice->infaktUrl(),
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'more' => max(0, $total - count($rows))];
    }
}

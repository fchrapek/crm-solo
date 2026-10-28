<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\MonthCloseRun;
use App\Services\Clients\HoursBank;
use App\Services\Clients\HoursBankLedgerRow;
use App\Services\Clients\MonthCloseWorklist;
use App\Services\Clients\MonthSettlement;
use App\Support\LocalCalendar;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The month close as agents read it: the worklist, a client's reports with the hours the Rozliczenia tab shows, and
 * what closing a month would freeze. The same services as the pages, inside the acting account, never a write.
 */
final class MonthCloseReader
{
    public function __construct(
        private readonly MonthCloseWorklist $worklist,
        private readonly MonthCloseTicker $ticker,
        private readonly HoursBank $bank,
        private readonly MonthSettlement $settlement,
    ) {}

    /** @return array<string, mixed> */
    public function overview(Account $account, ?string $period): array
    {
        $this->assertBilling($account);
        $period = $this->period($period);
        $rows = $this->worklist->rows($account, $period);
        $reports = $this->periodReports(array_map(fn (array $row): int => $row['client']->id, $rows), $period);

        return [
            'period' => $period,
            'clients' => array_map(function (array $row) use ($reports, $period): array {
                $client = $row['client'];
                $run = $row['run'];
                $report = $reports->get($client->id);
                $checklist = match (true) {
                    $run !== null => $this->ticker->checklist($run->setRelation('client', $client)),
                    $row['can_start_checklist'] => $this->ticker->template($client, $period),
                    default => null,
                };

                return [
                    'client_id' => $client->id,
                    'client' => $client->name,
                    'close_type' => $client->month_close_type ?? $run?->close_type,
                    'in_close' => $row['can_start_checklist'],
                    'checklist' => $checklist !== null ? Arr::only($checklist, ['status', 'resolved', 'total', 'steps']) : null,
                    'report' => [
                        'mode' => $run?->report_mode ?? $client->report_mode ?? MonthCloseRun::REPORT_MODE_REPORT,
                        'report_id' => $report?->id,
                        'status' => $report?->status,
                    ],
                    'settlement' => $row['settlement'] !== null ? Arr::except($row['settlement'], ['period']) : null,
                ];
            }, $rows),
        ];
    }

    /** @return array<string, mixed> */
    public function reportById(Account $account, string $id): array
    {
        $this->assertBilling($account);
        $report = ctype_digit($id)
            ? ClientReport::query()->where('account_id', $account->id)->whereHas('client', fn ($query) => $query->where('account_id', $account->id))->find((int) $id)
            : null;
        if ($report === null) {
            throw new ReferenceNotFoundException('report', $id);
        }

        return $this->report($report->client, $report);
    }

    /** @return array<string, mixed> */
    public function reportFor(Client $client, ?string $period): array
    {
        $this->assertBilling($client->account);
        $period = $this->period($period);
        $first = LocalCalendar::monthFrom($period);
        $report = ClientReport::coveringPeriod($client->id, $first->toDateString(), $first->endOfMonth()->toDateString());
        if ($report === null) {
            throw new ReferenceNotFoundException('report', "{$client->name} {$period}", "The client has no report for {$period}; list its reports with `reports`.");
        }

        return $this->report($client, $report);
    }

    /** @return array<string, mixed> */
    public function reports(Client $client): array
    {
        $this->assertBilling($client->account);
        $reports = ClientReport::query()
            ->where('client_id', $client->id)
            ->orderByDesc('period_end')
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get(['id', 'client_id', 'period_type', 'period_start', 'period_end', 'status', 'composer_key', 'needs_recalculation', 'generated_at', 'finalized_at', 'sent_at']);
        $governing = $this->governingIds($client, $reports);

        return [
            'client_id' => $client->id,
            'client' => $client->name,
            'total' => $reports->count(),
            'reports' => $reports->map(fn (ClientReport $report): array => $this->summary($report, $governing))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function settlementPreview(Client $client, string $period): array
    {
        $this->assertBilling($client->account);
        if (! LocalCalendar::isMonth($period)) {
            throw AgentRefusal::invalid('The period must be a month as YYYY-MM.');
        }

        return ['client_id' => $client->id, ...$this->settlement->previewFigures($client, $period)];
    }

    public function defaultPeriod(): string
    {
        return $this->ticker->defaultPeriod();
    }

    /** @return array<string, mixed> */
    private function report(Client $client, ClientReport $report): array
    {
        $governing = $this->governingIds($client, ClientReport::query()->where('client_id', $client->id)->whereDate('period_end', $report->period_end->toDateString())->get());
        $month = $report->coversWholeMonth() ? $this->bank->month($client, $report->period_start->format('Y-m')) : null;

        return [
            ...$this->summary($report, $governing),
            'client_id' => $client->id,
            'client' => $client->name,
            'hours' => HoursBankLedgerRow::figures($month, $report),
            'revision_count' => $report->revisions()->count(),
            'version' => $report->version(),
            'body_markdown' => $report->body_markdown,
            'untrusted' => ['body_markdown'],
        ];
    }

    /**
     * @param  array<int, true>  $governing
     * @return array<string, mixed>
     */
    private function summary(ClientReport $report, array $governing): array
    {
        return [
            'id' => $report->id,
            'period' => $report->period_start->format('Y-m'),
            'period_type' => $report->period_type,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'whole_month' => $report->coversWholeMonth(),
            'status' => $report->status,
            'governs' => isset($governing[$report->id]),
            'needs_recalculation' => (bool) $report->needs_recalculation,
            'composer_key' => $report->composer_key,
            'generated_at' => $report->generated_at?->toIso8601String(),
            'finalized_at' => $report->finalized_at?->toIso8601String(),
            'sent_at' => $report->sent_at?->toIso8601String(),
            'sent_on' => $report->sentOn(),
            'edit_url' => route('client-reports.edit', ['client' => $report->client_id, 'report' => $report->id]),
        ];
    }

    /**
     * The report the hours bank reads for each period end, keyed by id.
     *
     * @param  Collection<int, ClientReport>  $reports
     * @return array<int, true>
     */
    private function governingIds(Client $client, Collection $reports): array
    {
        $baseline = $client->account->settlementFrom();

        return $reports
            ->groupBy(fn (ClientReport $report): string => $report->period_end->toDateString())
            ->map(fn (Collection $rows): ?ClientReport => ClientReport::settling($rows, $baseline))
            ->filter()
            ->mapWithKeys(fn (ClientReport $report): array => [$report->id => true])
            ->all();
    }

    /**
     * Each client's whole-month report for the period, the one the hours bank reads.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ClientReport>
     */
    private function periodReports(array $ids, string $period): Collection
    {
        $first = LocalCalendar::monthFrom($period);

        return ClientReport::query()
            ->whereIn('client_id', $ids)
            ->where('period_type', ClientReport::PERIOD_MONTH)
            ->whereDate('period_start', $first->toDateString())
            ->whereDate('period_end', $first->endOfMonth()->toDateString())
            ->get(['id', 'client_id', 'period_type', 'period_start', 'period_end', 'status'])
            ->groupBy('client_id')
            ->map(fn (Collection $rows): ?ClientReport => ClientReport::governing($rows));
    }

    private function period(?string $period): string
    {
        if ($period === null || $period === '') {
            return $this->defaultPeriod();
        }
        if (! LocalCalendar::isMonth($period)) {
            throw AgentRefusal::invalid('The period must be a month as YYYY-MM.');
        }

        return $period;
    }

    private function assertBilling(Account $account): void
    {
        if (! $account->hasFeature('billing')) {
            throw AgentRefusal::featureOff('billing');
        }
    }
}

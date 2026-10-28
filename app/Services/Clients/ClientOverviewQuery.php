<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Invoice;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Clients\Fields\ClientFieldsQuery;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ClientOverviewQuery
{
    public function __construct(
        private readonly HoursBank $bank,
        private readonly ClientJournal $journal,
        private readonly ClientBillingMode $mode,
        private readonly ClientOrderSummary $order,
    ) {}

    /**
     * @param  CarbonInterface  $since  where "nowe" starts for this user (ClientViews)
     * @param  string|null  $mode  the billing mode when the caller already read it
     * @return array{overview: array<string, mixed>}
     */
    public function props(Client $client, CarbonInterface $since, ?string $mode = null): array
    {
        $mode ??= $this->mode->of($client);
        $billing = $client->account->hasFeature('billing');
        $fields = app(ClientFieldsQuery::class);
        // An account that bills keeps its hours here; its fields stay on Dane.
        $card = ! $billing && $fields->uses($client->account_id) ? $fields->card($client) : null;
        $common = [
            'card' => $card === null ? null : [...$card, 'fields' => array_values(array_filter($card['fields'], fn (array $field): bool => $field['on_overview'] && ! $field['archived']))],
            'sms' => $card === null ? null : $fields->messages($client, 5, overview: true),
            'sms_pending' => $card === null ? 0 : OutboundMessage::query()->where('client_id', $client->id)->where('status', OutboundMessage::PENDING)->count(),
            'sms_failed' => $card === null ? 0 : OutboundMessage::query()->where('client_id', $client->id)->where('status', OutboundMessage::FAILED)->count(),
            // Przegląd polls while one of these can still change status.
            'sms_live' => $card !== null && OutboundMessage::query()->where('client_id', $client->id)->whereIn('status', [OutboundMessage::PENDING, OutboundMessage::SENDING])->exists(),
            'month_close' => $billing ? $this->monthClose($client, LocalCalendar::previousMonth()) : null,
            'doing_count' => Task::query()
                ->open()
                ->where('list_name', TrelloListMapper::LANE_DOING)
                ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
                ->count(),
            'journal' => [
                'since' => CarbonImmutable::instance($since)->setTimezone(LocalCalendar::timezone())->toDateString(),
                'since_today' => CarbonImmutable::instance($since)->setTimezone(LocalCalendar::timezone())->isSameDay(LocalCalendar::today()),
                'count' => $this->journal->rowsSince($client, $since)->count(),
            ],
        ];

        if (! $billing) {
            return ['overview' => [...$common, 'report_due' => null, 'hours' => null, 'settlement' => null, 'order' => null, 'order_waiting' => null]];
        }

        if ($mode === ClientBillingMode::ORDER) {
            return [
                'overview' => [
                    ...$common,
                    'report_due' => $this->reportDue($client, null),
                    'hours' => null,
                    'settlement' => null,
                    'order' => $this->order->props($client),
                    'order_waiting' => [
                        'task' => $this->nextDatedTask($client),
                        'invoice' => $this->unpaidInvoice($client),
                    ],
                ],
            ];
        }

        $previous = $this->bank->month($client, LocalCalendar::previousMonth());
        $state = $this->bank->settlement($client);
        // The hours are the active month's, as on Lista and in crm brief; a waiting report is its own reminder.
        $hours = $this->bank->month($client, $state->activePeriod);

        return [
            'overview' => [
                ...$common,
                'report_due' => $this->reportDue($client, $previous),
                'hours' => [...$hours->toArray(), 'forfeited' => $hours->forfeitedHours()],
                'order' => null,
                'order_waiting' => null,
                'settlement' => [
                    'active_period' => $state->activePeriod,
                    'current_period' => $state->currentPeriod,
                    'open_behind' => $state->openBehind(),
                ],
            ],
        ];
    }

    /** @return array{task_id: int, name: string, due: string, running_id: int|null}|null */
    private function nextDatedTask(Client $client): ?array
    {
        $task = Task::query()
            ->open()
            ->whereNotNull('due_date')
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id)->where('account_id', $client->account_id))
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();

        if ($task === null) {
            return null;
        }

        return [
            'task_id' => $task->id,
            'name' => $task->name,
            'due' => (string) $task->dueDay(),
            'running_id' => TimeEntry::query()
                ->where('task_id', $task->id)
                ->where('account_id', $client->account_id)
                ->whereNull('end_time')
                ->whereNull('actor_via')
                ->value('id'),
        ];
    }

    /**
     * The unpaid invoice due first; a draft is not issued yet and a paid one asks nothing.
     *
     * @return array{id: int, number: string|null, payment_date: string|null, overdue: bool, external_url: string|null}|null
     */
    private function unpaidInvoice(Client $client): ?array
    {
        $invoice = Invoice::query()
            ->where('client_id', $client->id)
            ->where('account_id', $client->account_id)
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['paid', 'draft']))
            ->orderByRaw('CASE WHEN payment_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->first();

        return $invoice === null ? null : [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'payment_date' => $invoice->payment_date?->toDateString(),
            'overdue' => $invoice->isOverdueOn(LocalCalendar::todayDate()),
            'external_url' => $invoice->infaktUrl(),
        ];
    }

    /**
     * The oldest ended month whose report is not sent; else last month's, when a retainer client gets one and none exists.
     * The report mode only silences that reminder: a report already written stays waiting until it is sent.
     *
     * @return array{period: string, report_id: int|null, status: string|null}|null
     */
    private function reportDue(Client $client, ?HoursBankMonth $previous): ?array
    {
        $monthReports = fn () => ClientReport::query()
            ->where('client_id', $client->id)
            ->where('period_type', ClientReport::PERIOD_MONTH);

        $unsent = $monthReports()
            ->whereDate('period_start', '<', LocalCalendar::monthFrom(LocalCalendar::currentMonth())->toDateString())
            ->where('status', '!=', ClientReport::STATUS_SENT)
            ->whereNotIn('period_start', $monthReports()->where('status', ClientReport::STATUS_SENT)->select('period_start'))
            ->orderBy('period_start')
            ->orderByRaw("CASE status WHEN 'finalized' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->first(['id', 'period_start', 'status']);

        if ($unsent !== null) {
            return ['period' => $unsent->period_start->format('Y-m'), 'report_id' => $unsent->id, 'status' => $unsent->status];
        }

        $expected = $previous !== null
            && $previous->hasRetainer()
            && ($client->report_mode ?? MonthCloseRun::REPORT_MODE_REPORT) === MonthCloseRun::REPORT_MODE_REPORT
            && ! $monthReports()->whereDate('period_start', LocalCalendar::monthFrom($previous->period)->toDateString())->exists();

        return $expected ? ['period' => $previous->period, 'report_id' => null, 'status' => null] : null;
    }

    /**
     * The oldest close still open up to last month; else what starting last month's would seed, until it is completed.
     *
     * @return array{period: string, started: bool, done: int, total: int}|null
     */
    private function monthClose(Client $client, string $previousPeriod): ?array
    {
        $run = $client->monthCloseRuns()
            ->reorder()
            ->where('status', '!=', MonthCloseRun::STATUS_COMPLETED)
            ->where('period', '<=', $previousPeriod)
            ->orderBy('period')
            ->withCount([
                'steps',
                'steps as resolved_count' => fn ($q) => $q->where('state', '!=', MonthCloseStep::STATE_PENDING),
            ])
            ->first();

        if ($run !== null) {
            return ['period' => $run->period, 'started' => true, 'done' => (int) $run->resolved_count, 'total' => (int) $run->steps_count];
        }

        if ($client->month_close_type === null || ! $client->include_in_month_close || $client->monthCloseRuns()->where('period', $previousPeriod)->exists()) {
            return null;
        }

        return ['period' => $previousPeriod, 'started' => false, 'done' => 0, 'total' => count(MonthCloseRun::stepTemplateFor($client))];
    }
}

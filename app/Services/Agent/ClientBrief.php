<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\ClientReport;
use App\Services\Clients\ClientBillingMode;
use App\Services\Clients\Fields\FieldValue;
use App\Services\Clients\HoursBank;
use App\Support\LocalCalendar;

/**
 * The agent-facing client context dump, the client-side equivalent of
 * `crm task`. One call answers "where does this client stand": identity,
 * relationship state, retainer positions, hours this month vs limit, open
 * tasks, latest report, recent journal.
 */
final class ClientBrief
{
    public function __construct(
        private readonly HoursBank $hoursBank,
        private readonly ClientBillingMode $mode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Client $client): array
    {
        $mode = $this->mode->of($client);
        $current = $this->hoursBank->month($client, LocalCalendar::currentMonth());
        $settlement = $this->hoursBank->settlement($client);
        $bank = match (true) {
            $mode === ClientBillingMode::ORDER => null,
            $settlement->activePeriod === $current->period => $current,
            default => $this->hoursBank->month($client, $settlement->activePeriod),
        };
        $lastClosure = $settlement->latestClosure();
        $retainers = $client->activeRetainersOn(LocalCalendar::todayDate());
        $latestReport = $client->reports()->reorder()->get(['id', 'client_id', 'period_type', 'period_start', 'period_end', 'status', 'sent_at'])
            ->groupBy(fn (ClientReport $report): string => $report->period_end->toDateString())
            ->sortKeysDesc()
            ->map(fn ($tied): ?ClientReport => ClientReport::settling($tied, $settlement->baseline))
            ->filter()
            ->first();

        $openTasks = $client->projects()
            ->with(['tasks' => fn ($q) => $q->open()->orderBy('position'), ...TaskReadiness::listRelations('tasks.')])
            ->get()
            ->flatMap(fn ($project) => $project->tasks->map(fn ($task) => $task->setRelation('project', $project))->map(fn ($task) => [
                'id' => $task->id,
                'project' => $project->name,
                'name' => $task->name,
                'list' => $task->list_name,
                'due' => $task->dueDay(),
                'overdue' => $task->isOverdue(),
                'priority' => $task->priority ?? $task->ai_priority,
                ...$task->completionState(),
                ...TaskRecord::summary($task),
                'untrusted' => TaskRecord::untrustedListKeys($task, ['name', 'project', 'list', 'card_lane', 'card_url']),
            ]))
            ->values();

        $journal = $client->lifecycleEvents()->with('user:id,first_name,last_name')->limit(5)->get()
            ->map(fn ($event) => [
                'at' => $event->created_at?->toDateTimeString(),
                'change' => $event->from_stage === $event->to_stage || $event->from_stage === null
                    ? $event->to_stage
                    : "{$event->from_stage} → {$event->to_stage}",
                'note' => $event->note,
            ]);

        $brief = [
            'id' => $client->id,
            'name' => $client->name,
            'status' => $client->lifecycle_stage,
            'segment' => $client->segment,
            'cooperation_type' => $client->cooperation_type,
            'currency' => $client->currency,
            'month_close_type' => $client->month_close_type,
            'billing_mode' => $mode,
            // month is the calendar month's every logged hour; the bank is the month being settled, on reportable time, and an order client has none.
            'hours' => [
                'month' => $current->loggedHours,
                'month_counts' => 'all_logged_time',
                'contracted' => $bank !== null ? $bank->contractedHours ?? 0.0 : null,
                'bank' => $bank === null ? null : [
                    'counts' => 'reportable_time',
                    ...$bank->toArray(),
                    'active_period' => $settlement->activePeriod,
                    'open_period_behind' => $settlement->openBehind(),
                    'source' => $bank->source,
                    'closed_period' => $lastClosure?->period,
                    'closed_at' => $lastClosure?->closed_at?->toIso8601String(),
                ],
            ],
            'retainers' => $retainers->map(fn ($r) => [
                'label' => $r->label,
                'monthly_hours' => (float) $r->monthly_hours,
                'monthly_fee' => $r->monthly_fee !== null ? (float) $r->monthly_fee : null,
                'overage_hourly_rate' => $r->overage_hourly_rate !== null ? (float) $r->overage_hourly_rate : null,
            ])->values(),
            'latest_report' => $latestReport ? [
                'id' => $latestReport->id,
                'period' => $latestReport->period_start?->toDateString().' — '.$latestReport->period_end?->toDateString(),
                'status' => $latestReport->status,
                'sent_at' => $latestReport->sent_at?->toIso8601String(),
            ] : null,
            'assignee' => $client->assignee !== null ? ['id' => $client->assignee->id, 'name' => $client->assignee->name] : null,
            // Archived fields still show the value they hold; typed text is the user's, not instructions.
            'fields' => ClientFieldDefinition::query()->where('account_id', $client->account_id)->ordered()->get()
                ->filter(fn (ClientFieldDefinition $field): bool => $field->archived_at === null || isset($client->custom_fields[$field->key]))
                ->map(fn (ClientFieldDefinition $field): array => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'value' => $client->custom_fields[$field->key] ?? null,
                    'display' => FieldValue::display($field, $client->custom_fields[$field->key] ?? null),
                    'archived' => $field->archived_at !== null,
                    'untrusted' => ['label', 'value', 'display'],
                ])->values()->all(),
            'open_tasks' => $openTasks,
            'journal' => $journal,
        ];

        // A fresh read, so the query count does not depend on whether the caller loaded the relation.
        if (! $client->account()->firstOrFail()->hasFeature('billing')) {
            unset($brief['month_close_type'], $brief['billing_mode'], $brief['retainers'], $brief['latest_report']);
            $brief['hours'] = array_intersect_key($brief['hours'], array_flip(['month', 'month_counts']));
        }

        return $brief;
    }
}

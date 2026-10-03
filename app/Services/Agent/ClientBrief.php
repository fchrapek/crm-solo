<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Support\LocalCalendar;

/**
 * The agent-facing client context dump — the client-side equivalent of
 * CRM_TASK.md. One call answers "where does this client stand": identity,
 * relationship state, retainer positions, hours this month vs limit, open
 * tasks, latest report, recent journal.
 */
final class ClientBrief
{
    /**
     * @return array<string, mixed>
     */
    public function for(Client $client): array
    {
        $monthStart = LocalCalendar::monthRange(LocalCalendar::currentMonth())[0];
        $monthMinutes = (int) $client->timeEntries()->where('start_time', '>=', $monthStart)->sum('duration_minutes');
        $retainers = $client->activeRetainersOn(LocalCalendar::todayDate());
        $latestReport = $client->reports()->orderByDesc('period_start')->first();

        $openTasks = $client->projects()
            ->with(['tasks' => fn ($q) => $q->open()->orderBy('position'), ...TaskReadiness::listRelations('tasks.')])
            ->get()
            ->flatMap(fn ($project) => $project->tasks->map(fn ($task) => [
                'id' => $task->id,
                'project' => $project->name,
                'name' => $task->name,
                'list' => $task->list_name,
                'due' => $task->dueDay(),
                'overdue' => $task->isOverdue(),
                'priority' => $task->priority ?? $task->ai_priority,
                'cli' => $task->cli,
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

        return [
            'id' => $client->id,
            'name' => $client->name,
            'status' => $client->lifecycle_stage,
            'segment' => $client->segment,
            'cooperation_type' => $client->cooperation_type,
            'currency' => $client->currency,
            'month_close_type' => $client->month_close_type,
            'hours' => [
                'month' => round($monthMinutes / 60, 2),
                'contracted' => (float) $retainers->sum('monthly_hours'),
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
            ] : null,
            'open_tasks' => $openTasks,
            'journal' => $journal,
        ];
    }
}

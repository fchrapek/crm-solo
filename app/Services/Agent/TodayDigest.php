<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Lead;
use App\Models\MonthCloseRun;
use App\Models\Task;
use App\Models\TimeEntry;

/**
 * The cross-client morning glance: what needs attention today. Mirrors the
 * dashboard's attention rule (priority high, or AI-high with no human override)
 * plus overdue tasks, open month-close runs for the current period, hot leads
 * still in play, and any running time entry.
 */
final class TodayDigest
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $attention = Task::query()
            ->where('is_completed', false)
            ->whereNull('archived_at')
            ->where(function ($q): void {
                $q->where('priority', 'high')
                    ->orWhere(fn ($inner) => $inner->whereNull('priority')->where('ai_priority', 'high'))
                    ->orWhere(fn ($inner) => $inner->whereNotNull('due_date')->where('due_date', '<', now()->startOfDay()));
            })
            ->with('project.client:id,name')
            ->orderBy('due_date')
            ->limit(30)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'name' => $task->name,
                'client' => $task->project?->client?->name,
                'project' => $task->project?->name,
                'due' => $task->due_date?->toDateString(),
                'overdue' => $task->due_date !== null && $task->due_date->isPast(),
            ]);

        $monthClose = MonthCloseRun::query()
            ->where('period', now()->format('Y-m'))
            ->where('status', 'open')
            ->with('client:id,name')
            ->get()
            ->map(fn ($run) => [
                'client' => $run->client?->name,
                'period' => $run->period,
                'type' => $run->close_type,
            ]);

        $hotLeads = Lead::query()
            ->get()
            ->filter(fn (Lead $lead): bool => $lead->tier() === Lead::TIER_GOLD && ! $lead->isWon())
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'brand' => $lead->pipeline,
                'stage' => $lead->stage,
                'routing' => $lead->tierRouting(),
            ])
            ->values();

        $running = TimeEntry::query()
            ->whereNull('end_time')
            ->with('client:id,name', 'task:id,name')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'client' => $entry->client?->name,
                'task' => $entry->task?->name,
                'started' => $entry->start_time?->toDateTimeString(),
            ]);

        return [
            'attention_tasks' => $attention,
            'month_close_open' => $monthClose,
            'hot_leads' => $hotLeads,
            'running_time_entries' => $running,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ReportDataAggregator
{
    public function aggregate(
        Client $client,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $periodType,
        string $locale = 'en',
    ): ReportContext {
        $start = $periodStart->copy()->startOfDay();
        $end = $periodEnd->copy()->endOfDay();

        // Pull every time entry in window for accounting / debugging, then
        // narrow to reportable ones (linked to a task with is_reportable=true)
        // for everything the client sees. Non-reportable tasks still consume
        // your real hours but never appear in the report — their scope
        // shows up via clients.report_baseline_markdown instead.
        $allTimeEntries = TimeEntry::query()
            ->where('client_id', $client->id)
            ->whereBetween('start_time', [$start, $end])
            ->orderBy('start_time')
            ->get();

        $reportableTaskIds = Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->where('is_reportable', true)
            ->pluck('id');

        $timeEntries = $allTimeEntries->filter(
            fn (TimeEntry $entry): bool => $entry->task_id !== null
                && $reportableTaskIds->contains($entry->task_id),
        )->values();

        $actualMinutes = (int) $timeEntries->sum('duration_minutes');
        $actualHours = round($actualMinutes / 60, 2);

        $taskMinutes = $timeEntries->groupBy('task_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('duration_minutes'));

        // Tasks: reportable, AND either (logged time in window) or (completed
        // in window). Non-reportable tasks are intentionally invisible —
        // their scope is covered by the baseline markdown injection.
        $taskIdsFromTime = $timeEntries->pluck('task_id')->filter()->unique();
        $completedTaskIds = Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->where('is_reportable', true)
            ->where('is_completed', true)
            ->whereBetween('updated_at', [$start, $end])
            ->pluck('id');

        $taskIds = $taskIdsFromTime->merge($completedTaskIds)->unique()->values();

        $tasks = Task::query()
            ->with('project:id,name')
            ->whereIn('id', $taskIds)
            ->get()
            ->map(function (Task $task) use ($taskMinutes): array {
                return [
                    'id' => $task->id,
                    'name' => $task->name,
                    'description' => $task->description,
                    'project' => $task->project?->name,
                    'completed_at' => $task->is_completed ? $task->updated_at?->toIso8601String() : null,
                    'minutes' => (int) ($taskMinutes[$task->id] ?? 0),
                    'type' => $task->type,
                ];
            })
            ->values();

        $timeEntryRows = $timeEntries->map(function (TimeEntry $entry) use ($tasks): array {
            return [
                'id' => $entry->id,
                'task_id' => $entry->task_id,
                'project' => $tasks->firstWhere('id', $entry->task_id)['project'] ?? null,
                'description' => $entry->description,
                'start' => $entry->start_time?->toIso8601String() ?? '',
                'end' => $entry->end_time?->toIso8601String(),
                'minutes' => (int) $entry->duration_minutes,
                'source' => $entry->source,
            ];
        })->values();

        $retainer = $client->activeRetainerOn($start);

        return new ReportContext(
            client: $client,
            periodStart: $start,
            periodEnd: $end,
            periodType: $periodType,
            retainer: $retainer,
            actualHours: $actualHours,
            tasks: $tasks,
            timeEntries: $timeEntryRows,
            locale: $locale,
            openingBalanceHours: $this->openingBalanceFor($client, $start),
            rolloverCapHours: $retainer?->rollover_cap_hours !== null
                ? (float) $retainer->rollover_cap_hours
                : null,
        );
    }

    /**
     * The balance the client carries into this period, taken from the closing
     * balance of the most recent report that ended before it starts.
     *
     * Read from the previous report rather than recomputed from scratch: that
     * report is what the client was actually shown, and its opening balance may
     * have been adjusted by hand.
     */
    private function openingBalanceFor(Client $client, Carbon $start): float
    {
        $previous = $client->reports()
            ->where('period_end', '<', $start)
            ->orderByDesc('period_end')
            ->first();

        return $previous !== null ? $previous->closingBalanceHours() : 0.0;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Clients\HoursBank;
use App\Support\LocalCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ReportDataAggregator
{
    public function __construct(private readonly HoursBank $hoursBank) {}

    /**
     * The opening balance is carried in from the previous settled point unless the
     * caller passes one, as a regenerate does with the report's saved figure. A
     * closed month's figures come from its closure, whatever the entries say now.
     */
    public function aggregate(
        Client $client,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $periodType,
        string $locale = 'en',
        ?float $openingBalanceHours = null,
        ?ClosureFigures $closure = null,
    ): ReportContext {
        $start = $periodStart->copy()->startOfDay();
        $end = $periodEnd->copy()->endOfDay();
        // The period is local calendar dates; the timestamps it selects are UTC.
        // An entry belongs to the day it started on, even when it runs past midnight.
        [$from, $to] = LocalCalendar::dateRange($start, $end);

        // Non-reportable scope is told by the baseline markdown instead; an entry counts for its own client_id, wherever its task lives now.
        $timeEntries = TimeEntry::query()
            ->reportable()
            ->where('client_id', $client->id)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->orderBy('start_time')
            ->get();

        $actualMinutes = (int) $timeEntries->sum('duration_minutes');
        $actualHours = round($actualMinutes / 60, 2);

        $taskMinutes = $timeEntries->groupBy('task_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('duration_minutes'));

        // Tasks: reportable, AND either (logged time in window) or (finished
        // in window with no time logged before it). Non-reportable tasks are
        // intentionally invisible: the baseline markdown covers their scope.
        // finished_at is the completion date itself, so a later edit or sync
        // cannot move a task into another period. A task worked in an earlier
        // period and only closed in this one belongs to the period of its work.
        $taskIdsFromTime = $timeEntries->pluck('task_id')->filter()->unique();
        $completedTaskIds = Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->where('is_reportable', true)
            ->where('finished_at', '>=', $from)
            ->where('finished_at', '<', $to)
            ->whereNotIn('id', TimeEntry::query()
                ->select('task_id')
                ->whereNotNull('task_id')
                ->where('client_id', $client->id)
                ->where('start_time', '<', $from))
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
                    'completed_at' => $task->finished_at?->toIso8601String(),
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
            actualHours: $closure?->used ?? $actualHours,
            tasks: $tasks,
            timeEntries: $timeEntryRows,
            locale: $locale,
            openingBalanceHours: $closure?->opening ?? $openingBalanceHours ?? $this->hoursBank->openingBalance($client, $start),
            rolloverCapHours: $closure !== null ? $closure->cap : ($retainer?->rollover_cap_hours !== null
                ? (float) $retainer->rollover_cap_hours
                : null),
            closure: $closure,
        );
    }
}

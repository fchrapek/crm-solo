<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Actions\Clockify\PushTimeEntryToClockify;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;

/**
 * Live timers: an open TimeEntry with no end_time — exactly what the
 * dashboard's dangling-timer check watches. Overlapping timers are legal
 * (parallel agent sessions bill concurrently), so a second open timer is
 * reported, never blocked.
 */
final class TimerService
{
    public function start(Task $task, ?string $description = null, bool $billable = true): TimeEntry
    {
        return TimeEntry::create([
            'account_id' => $task->project?->account_id,
            'project_id' => $task->project_id,
            'client_id' => $task->project?->client_id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'title' => $task->name,
            'description' => $description ?: $task->name,
            'start_time' => now(),
            'end_time' => null,
            'duration_minutes' => 0,
            'billable' => $billable,
        ]);
    }

    public function stop(TimeEntry $entry, ?string $description = null): TimeEntry
    {
        $end = now();
        $minutes = (int) $entry->start_time->diffInMinutes($end);

        $entry->update([
            'end_time' => $end,
            'duration_minutes' => $minutes,
            'description' => $description ?: $entry->description,
        ]);

        // Best-effort — no-ops when Clockify isn't configured.
        (new PushTimeEntryToClockify)($entry->load('project.client'));

        return $entry;
    }

    /**
     * Timers already running — reported alongside a fresh start so the caller
     * sees the overlap.
     *
     * @return Collection<int, TimeEntry>
     */
    public function open(?int $accountId = null): Collection
    {
        return TimeEntry::query()
            ->whereNull('end_time')
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->with('task:id,name')
            ->get();
    }
}

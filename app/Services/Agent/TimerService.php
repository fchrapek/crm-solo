<?php

declare(strict_types=1);

namespace App\Services\Agent;

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
        return TimeEntry::startFor($task, TimeEntry::SOURCE_MANUAL, $description, $billable);
    }

    public function stop(TimeEntry $entry, ?string $description = null): TimeEntry
    {
        $entry->stopNow($description);

        return $entry;
    }

    /**
     * What timer-start returns on every transport. A task name that came from
     * a card or an email is listed under the item's `untrusted`.
     *
     * @param  Collection<int, TimeEntry>  $open
     * @return array<string, mixed>
     */
    public function startPayload(TimeEntry $entry, Task $task, Collection $open): array
    {
        return [
            'entry_id' => $entry->id,
            'task_id' => $task->id,
            'client' => $task->project?->client?->name,
            'started_at' => $entry->start_time?->toIso8601String(),
            'other_open_timers' => $open->map(fn (TimeEntry $other): array => [
                'id' => $other->id,
                'task' => $other->task?->name,
                'untrusted' => $other->task !== null && TaskRecord::isExternal($other->task) ? ['task'] : [],
            ])->values()->all(),
            'untrusted' => [],
        ];
    }

    /**
     * What timer-stop returns on every transport.
     *
     * @return array<string, mixed>
     */
    public function stopPayload(TimeEntry $entry): array
    {
        return [
            'entry_id' => $entry->id,
            'minutes' => $entry->duration_minutes,
            'task' => $entry->task?->name,
            'client' => $entry->client?->name,
            'untrusted' => $entry->task !== null && TaskRecord::isExternal($entry->task) ? ['task'] : [],
        ];
    }

    /**
     * Timers already running — reported alongside a fresh start so the caller
     * sees the overlap.
     *
     * @return Collection<int, TimeEntry>
     */
    public function open(int $accountId): Collection
    {
        return TimeEntry::query()
            ->whereNull('end_time')
            ->where('account_id', $accountId)
            ->with('task:id,name,source,trello_card_id')
            ->get();
    }
}

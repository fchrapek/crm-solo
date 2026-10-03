<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;

final readonly class TaskFinishResult
{
    /**
     * @param  Collection<int, TimeEntry>  $stoppedEntries  timers the finish closed
     */
    public function __construct(
        public bool $wasAlreadyDone,
        public Collection $stoppedEntries,
    ) {}

    /**
     * @return list<array{id: int, minutes: int}>
     */
    public function stoppedTimers(): array
    {
        return $this->stoppedEntries
            ->map(fn (TimeEntry $entry) => ['id' => (int) $entry->id, 'minutes' => (int) $entry->duration_minutes])
            ->values()
            ->all();
    }

    /**
     * What the agent verbs report back after a finish.
     *
     * @return array<string, mixed>
     */
    public function payload(Task $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'was_already_done' => $this->wasAlreadyDone,
            ...$task->completionState(),
            'stopped_timers' => $this->stoppedTimers(),
        ];
    }
}

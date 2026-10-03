<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Integrations\Trello\TrelloListMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one way to tick and untick a task, used by the day screen, the task
 * pages, the agent board, `crm task-done` and MCP `task_done`.
 *
 * Finishing stops the task's timers first, so finished work never keeps
 * billing. A manual task moves to Done; a Trello card keeps everything
 * Trello owns (list, completion) and only records `finished_at`, which the
 * sync leaves alone.
 */
final class TaskCompletion
{
    /**
     * Idempotent: on a task already done it still stops running timers, and
     * keeps the finish date and the recurring successor it already has.
     */
    public function finish(Task $task): TaskFinishResult
    {
        return DB::transaction(function () use ($task): TaskFinishResult {
            $this->lockAndReload($task);

            $stopped = TimeEntry::query()
                ->where('task_id', $task->id)
                ->whereNull('end_time')
                ->get()
                ->filter(fn (TimeEntry $entry) => $entry->stopNow());

            $wasDone = $task->isDone();

            $update = ['finished_at' => $task->finished_at ?? now()];
            if (! $task->hasTrelloCard()) {
                $update['list_name'] = TrelloListMapper::LANE_DONE;
                $update['is_completed'] = true;
            }
            // On the agent board (a CLI set) the card lands in Done even if it never had a lane.
            if ($task->agent_lane !== null || $task->cli !== null) {
                $update['agent_lane'] = Task::AGENT_LANE_DONE;
            }
            $task->update($update);

            if (! $wasDone) {
                $task->spawnRecurringInstance();
            }

            return new TaskFinishResult($wasDone, $stopped->values());
        });
    }

    /**
     * Reverses a tick. A manual task leaves Done for $lane (To-Do when none
     * is given); a Trello card only loses `finished_at`. Checked on the row
     * under its lock, so a card the sync completed a moment ago is refused
     * for every caller, not reopened.
     *
     * @throws ValidationException when the task cannot be reopened from the CRM
     */
    public function unfinish(Task $task, ?string $lane = null): void
    {
        DB::transaction(function () use ($task, $lane): void {
            $this->lockAndReload($task);

            if (! $task->canUnfinish()) {
                throw ValidationException::withMessages(['task_id' => $task->hasTrelloCard()
                    ? __('This card is completed on its Trello board. Move it back there to reopen it.')
                    : __('This task is not done.')]);
            }

            $update = ['finished_at' => null];
            if (! $task->hasTrelloCard()) {
                $update['is_completed'] = false;
                $update['list_name'] = $lane ?? ($task->list_name === TrelloListMapper::LANE_DONE ? TrelloListMapper::LANE_TODO : $task->list_name);
            }
            if ($task->agent_lane === Task::AGENT_LANE_DONE) {
                $update['agent_lane'] = Task::AGENT_LANE_BACKLOG;
            }

            $task->update($update);
        });
    }

    /**
     * A lane move on a manual task: into Done is a finish (again on a task
     * already done: its timers stop, nothing else changes), out of a finished
     * state is an untick, anything else only changes the lane.
     */
    public function moveToLane(Task $task, string $lane): void
    {
        DB::transaction(fn () => $this->moveLockedToLane($task, $lane));
    }

    private function moveLockedToLane(Task $task, string $lane): void
    {
        $this->lockAndReload($task);

        if ($lane === TrelloListMapper::LANE_DONE) {
            $this->finish($task);
        } elseif ($task->isDone()) {
            $this->unfinish($task, $lane);
        } elseif (! $task->hasTrelloCard()) {
            $task->update(['list_name' => $lane]);
        }
    }

    /**
     * Decisions read the row as it is now, held until the transaction ends,
     * so a sync or a second request cannot slip a stale value in between.
     */
    private function lockAndReload(Task $task): void
    {
        $current = Task::query()->whereKey($task->id)->lockForUpdate()->first();
        if ($current !== null) {
            $task->setRawAttributes($current->getAttributes(), true);
        }
    }
}

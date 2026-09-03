<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Agent verb: complete a task through the canonical path (Done list,
 * completed flag, agent lane mirror, recurrence spawn) — identical to
 * completing it in the UI, so an agent finishing work can close the loop
 * without a browser.
 */
final class CrmTaskDone extends Command
{
    protected $signature = 'crm:task-done {task : Task id} {--json : Machine-readable output}';

    protected $description = 'Complete a task (canonical path: Done + recurrence spawn), e.g. from an agent session';

    public function handle(): int
    {
        $task = Task::find((int) $this->argument('task'));
        if ($task === null) {
            $this->error('No task with id ['.$this->argument('task').'].');

            return self::FAILURE;
        }

        $alreadyDone = (bool) $task->is_completed;
        $task->markDone();
        $task->refresh();

        $successor = $task->latestOpenSuccessor();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'id' => $task->id,
                'name' => $task->name,
                'was_already_done' => $alreadyDone,
                'recurring_successor_id' => $successor?->id,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Done: #{$task->id} {$task->name}".($alreadyDone ? ' (was already completed)' : ''));
        if ($successor !== null && ! $alreadyDone) {
            $this->line("Recurring successor: #{$successor->id} due {$successor->due_date?->toDateString()}");
        }

        return self::SUCCESS;
    }
}

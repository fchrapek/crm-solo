<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Task;
use App\Services\Agent\ReferenceNotFoundException;
use App\Services\Tasks\TaskCompletion;
use Illuminate\Console\Command;

/**
 * Agent verb: tick a task through the same path as the day screen. Running
 * timers on the task stop first; a manual task moves to Done, a Trello card
 * records only that the owner's work is finished.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTaskDone extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:task-done {task : Task id} {--json : Machine-readable output}';

    protected $description = 'Finish a task (stops its timers; manual: Done + recurrence, Trello: finished_at only)';

    public function handle(TaskCompletion $completion): int
    {
        $id = (string) $this->argument('task');
        $task = ctype_digit($id)
            ? Task::query()->whereHas('project', fn ($q) => $q->where('account_id', $this->actingIdentity()->account->id))->find((int) $id)
            : null;
        if ($task === null) {
            return $this->referenceFailure(new ReferenceNotFoundException('task', $id));
        }

        $result = $completion->finish($task);
        $alreadyDone = $result->wasAlreadyDone;
        $task->refresh();

        $successor = $task->latestOpenSuccessor();

        if ($this->option('json')) {
            $this->raw($this->encodeJson([
                ...$result->payload($task),
                'recurring_successor_id' => $successor?->id,
            ]));

            return self::SUCCESS;
        }

        if ($alreadyDone) {
            $this->raw("Already finished: #{$task->id}".($task->finished_at ? " (finished {$task->finished_at->toDateString()})" : '').'. Nothing new to finish.');
        } else {
            $this->raw("Done: #{$task->id}");
        }
        $this->fenced(['Task: '.$this->literal($task->name)]);
        foreach ($result->stoppedTimers() as $timer) {
            $this->line("Stopped timer #{$timer['id']} at {$timer['minutes']} min");
        }
        if ($task->hasTrelloCard()) {
            $this->raw('Trello card stays on: '.$this->literal($task->list_name));
        }
        if ($successor !== null && ! $alreadyDone) {
            $this->line("Recurring successor: #{$successor->id} due {$successor->due_date?->toDateString()}");
        }

        return self::SUCCESS;
    }
}

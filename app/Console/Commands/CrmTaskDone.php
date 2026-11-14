<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Task;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\BilledTimeEditor;
use App\Services\Agent\ReferenceNotFoundException;
use App\Services\Agent\TaskBillingNotes;
use App\Services\Tasks\TaskCompletion;
use Illuminate\Console\Command;

/**
 * Agent verb: tick a task through the same path as the day screen. Running
 * timers on the task stop first; a manual task moves to Done, a Trello card
 * records only that the owner's work is finished. With --billed the task is
 * billed after it is finished; a refused bill leaves it finished and exits 1.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTaskDone extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:task-done
                            {task : Task id}
                            {--billed= : Billed hours for the task, set after it is finished}
                            {--month= : The month the billed hours belong to (default: the month the task was last worked in)}
                            {--note= : Internal note in Polish for the billed hours}
                            {--json : Machine-readable output}';

    protected $description = 'Finish a task (stops its timers; manual: Done, Trello: finished_at only)';

    public function handle(TaskCompletion $completion, BilledTimeEditor $billing): int
    {
        $billed = $this->option('billed');
        if ($billed !== null && ! is_numeric($billed)) {
            return $this->refusal(AgentRefusal::invalid('--billed takes a number of hours.'));
        }
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

        $bill = null;
        $billRefusal = null;
        if ($billed !== null) {
            try {
                $bill = $billing->taskSet($this->actingIdentity()->account, (string) $task->id, $this->option('month') !== null ? (string) $this->option('month') : null, (float) $billed, false, $this->option('note') !== null ? (string) $this->option('note') : null);
            } catch (AgentRefusal $e) {
                $billRefusal = $e;
            }
        }
        $exit = $billRefusal !== null ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->raw($this->encodeJson([
                ...$result->payload($task),
                ...TaskBillingNotes::forTask($task),
                ...($billed !== null ? ['billed' => $billRefusal !== null ? $billRefusal->toPayload() : $bill] : []),
            ]));

            return $exit;
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
        foreach (TaskBillingNotes::forTask($task)['warnings'] as $warning) {
            $this->raw('WARNING '.$warning);
        }
        if ($bill !== null) {
            $this->raw('Billed: '.$this->literal((string) $bill['message']));
        }
        if ($billRefusal !== null) {
            $this->raw('ERROR billed hours not saved, the task is finished: '.$this->literal($billRefusal->getMessage()));
        }

        return $exit;
    }
}

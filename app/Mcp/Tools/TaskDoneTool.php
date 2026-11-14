<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\Task;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\BilledTimeEditor;
use App\Services\Agent\ReferenceNotFoundException;
use App\Services\Agent\TaskBillingNotes;
use App\Services\Tasks\TaskCompletion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Finish a task: stops its running timers, then a manual task moves to Done (next instance spawned when it recurs) while a Trello card only records finished_at and keeps the lane Trello gives it. With "billed_hours" (the owner said e.g. "billable 3h") the task is billed after it is finished, as task_billed_set does; a refused bill leaves the task finished and comes back under "billed" with its error, so tell the owner. "warnings" says when the task\'s time counts nowhere. Safe to call twice.')]
final class TaskDoneTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'task_done';

    public function handle(Request $request, TaskCompletion $completion, BilledTimeEditor $billing): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'integer'],
            'billed_hours' => ['sometimes', 'nullable', 'numeric'],
            'month' => ['sometimes', 'nullable', 'string'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], [
            'task.required' => 'Pass the numeric task id. client_brief and today both list task ids.',
            'task.integer' => 'Pass the numeric task id, not a name — task names are not unique enough to complete by.',
        ]);

        $task = Task::query()
            ->whereHas('project', fn ($q) => $q->where('account_id', $this->identity()->account->id))
            ->find($validated['task']);

        if ($task === null) {
            return $this->referenceError(new ReferenceNotFoundException('task', (string) $validated['task']));
        }

        $result = $completion->finish($task);
        $task->refresh();
        $billed = null;
        if (isset($validated['billed_hours'])) {
            try {
                $billed = $billing->taskSet($this->identity()->account, (string) $task->id, $validated['month'] ?? null, (float) $validated['billed_hours'], false, $validated['note'] ?? null);
            } catch (AgentRefusal $e) {
                $billed = $e->toPayload();
            }
        }

        return $this->payload([
            ...$result->payload($task),
            ...TaskBillingNotes::forTask($task),
            ...($billed !== null ? ['billed' => $billed] : []),
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->integer()
                ->description('Numeric task id.')
                ->required(),
            'billed_hours' => $schema->number()->description('Billed hours for the task, set after it is finished; needs the write:billing ability.'),
            'month' => $schema->string()->description('The month the billed hours belong to, YYYY-MM; default the month the task was last worked in.'),
            'note' => $schema->string()->description('Internal note in Polish for the billed hours.'),
        ];
    }
}

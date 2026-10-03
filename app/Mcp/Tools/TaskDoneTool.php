<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\Task;
use App\Services\Agent\ReferenceNotFoundException;
use App\Services\Tasks\TaskCompletion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Finish a task: stops its running timers, then a manual task moves to Done (agent-lane mirror, next instance spawned when it recurs) while a Trello card only records finished_at and keeps the lane Trello gives it. Safe to call twice.')]
final class TaskDoneTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'task_done';

    public function handle(Request $request, TaskCompletion $completion): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'integer'],
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

        return $this->payload([
            ...$result->payload($task),
            'recurring_successor_id' => $task->latestOpenSuccessor()?->id,
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
        ];
    }
}

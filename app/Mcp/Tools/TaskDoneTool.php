<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\Task;
use App\Services\Agent\ReferenceNotFoundException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Complete a task the canonical way: Done list, completed flag, agent-lane mirror, and the next instance spawned when the task recurs. Safe to call twice.')]
final class TaskDoneTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'task_done';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'integer'],
        ], [
            'task.required' => 'Pass the numeric task id. client_brief and today both list task ids.',
            'task.integer' => 'Pass the numeric task id, not a name — task names are not unique enough to complete by.',
        ]);

        $task = Task::find($validated['task']);

        if ($task === null) {
            return $this->referenceError(new ReferenceNotFoundException('task', (string) $validated['task']));
        }

        $alreadyDone = (bool) $task->is_completed;
        $task->markDone();
        $task->refresh();

        return $this->payload([
            'id' => $task->id,
            'name' => $task->name,
            'was_already_done' => $alreadyDone,
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

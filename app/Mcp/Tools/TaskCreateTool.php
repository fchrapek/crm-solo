<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TaskEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a task, the same as the task form. Pass the project, or the client to put it on the client\'s general project. On a project synced from Trello it is a CRM task: it never goes to the board, and the sync leaves it alone. Due is a local day: today, tomorrow or YYYY-MM-DD. Confirm the details with the user before calling.')]
final class TaskCreateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'task_create';

    public function handle(Request $request, TaskEditor $editor): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'project' => ['nullable', 'string'],
            'client' => ['nullable', 'string'],
            'list' => ['nullable', 'string'],
            'due' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'reportable' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Pass the task name.',
        ]);

        try {
            return $this->payload($editor->create($this->identity()->account->id, $validated));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The task name.')->required(),
            'project' => $schema->string()->description('Project id or name fragment. Pass this or client.'),
            'client' => $schema->string()->description('Client id or name fragment: the task goes on its general project. Pass this or project.'),
            'list' => $schema->string()->description('Backlog, To-Do (default), Doing, Testing or Done.'),
            'due' => $schema->string()->description('Due day: today, tomorrow or YYYY-MM-DD, local time.'),
            'description' => $schema->string()->description('What the task is about.'),
            'reportable' => $schema->boolean()->description('true counts the task\'s time in reports and the hours bank. Default false.'),
        ];
    }
}

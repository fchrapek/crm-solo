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

#[Description('Change a task the way the task page does: name, description, due day, list, project, reportable, archive or unarchive, reopen a done task, move its logged time onto its project (move_time). Only the fields passed change; an empty description or due clears it. A Trello card refuses what its board owns (trello_owned); reopening a card the CRM ticked works. There is no delete.')]
final class TaskEditTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'task_edit';

    public function handle(Request $request, TaskEditor $editor): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'string'],
            'name' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'due' => ['sometimes', 'nullable', 'string'],
            'list' => ['sometimes', 'nullable', 'string'],
            'project' => ['sometimes', 'nullable', 'string'],
            'reportable' => ['sometimes', 'nullable', 'boolean'],
            'archived' => ['sometimes', 'nullable', 'boolean'],
            'reopen' => ['sometimes', 'boolean'],
            'move_time' => ['sometimes', 'boolean'],
        ], [
            'task.required' => 'Pass a task id or a fragment of the task name.',
        ]);

        $input = array_intersect_key($validated, array_flip(['name', 'description', 'due', 'list', 'project', 'reportable', 'reopen', 'move_time']));
        // Over MCP an explicit null is a clear, as an empty string is.
        foreach (['description', 'due'] as $clearable) {
            if (array_key_exists($clearable, $validated) && $validated[$clearable] === null) {
                $input[$clearable] = '';
            }
        }
        $input['archive'] = isset($validated['archived']) ? (bool) $validated['archived'] : null;

        try {
            return $this->payload($editor->edit($this->identity()->account->id, $validated['task'], $input));
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
            'task' => $schema->string()->description('Task id, or a fragment of the task name.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description; an empty string clears it.'),
            'due' => $schema->string()->description('Due day: today, tomorrow or YYYY-MM-DD, local time; an empty string clears it.'),
            'list' => $schema->string()->description('Backlog, To-Do, Doing, Testing or Done. Done finishes the task; a list other than Done on a done task reopens it.'),
            'project' => $schema->string()->description('Move to this project: id or name fragment.'),
            'reportable' => $schema->boolean()->description('true counts the task\'s time in reports and the hours bank, false stops it. Closed months keep their figures.'),
            'archived' => $schema->boolean()->description('true archives the task, false takes it out of the archive.'),
            'reopen' => $schema->boolean()->description('Reopen a done task, to To-Do or to the list passed.'),
            'move_time' => $schema->boolean()->description('Move the task\'s logged time onto its project (and client), with a project change or alone. Time in a closed month or under a finalized or sent report stays and is listed in skipped_time.'),
        ];
    }
}

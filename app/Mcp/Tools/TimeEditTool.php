<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimeEntryEditor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Correct one time entry, by id (time_entries lists them), the way the time edit dialog does: minutes, start, end, task, description, title, billable. Only the fields passed change. minutes keeps the start and moves the end, or with end moves the start. Times are local wall-clock. A running entry takes no time change until it is stopped. An entry whose month is closed or whose report is finalized or sent refuses with period_settled, and so does a change that moves time into such a period. Overlapping entries are fine. There is no delete.')]
final class TimeEditTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'time_edit';

    public function handle(Request $request, TimeEntryEditor $editor): Response
    {
        $validated = $request->validate([
            'entry' => ['required', 'regex:/^\d+$/'],
            'minutes' => ['sometimes', 'nullable', 'integer'],
            'start' => ['sometimes', 'nullable', 'string'],
            'end' => ['sometimes', 'nullable', 'string'],
            'task' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'title' => ['sometimes', 'nullable', 'string'],
            'billable' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'entry.required' => 'Pass the time entry id.',
            'entry.regex' => 'Pass the time entry id as a number.',
        ]);

        $input = array_intersect_key($validated, array_flip(['minutes', 'start', 'end', 'task', 'description', 'title', 'billable']));
        // Over MCP an explicit null is a clear, as an empty string is.
        foreach (['description', 'title'] as $clearable) {
            if (array_key_exists($clearable, $validated) && $validated[$clearable] === null) {
                $input[$clearable] = '';
            }
        }

        try {
            return $this->payload($editor->edit($this->identity()->account->id, (string) $validated['entry'], $input));
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
            'entry' => $schema->string()->description('Time entry id.')->required(),
            'minutes' => $schema->integer()->description('New length in minutes, 1 or more. Keeps the start and moves the end; with end, moves the start.'),
            'start' => $schema->string()->description('New start, local: "YYYY-MM-DD HH:MM", or "HH:MM" on the entry\'s day.'),
            'end' => $schema->string()->description('New end, local: "YYYY-MM-DD HH:MM", or "HH:MM" on the entry\'s day. Not in the future.'),
            'task' => $schema->string()->description('Move the entry to this task: id or name fragment. Project and client follow the task.'),
            'description' => $schema->string()->description('New description; an empty string clears it.'),
            'title' => $schema->string()->description('New title; an empty string clears it.'),
            'billable' => $schema->boolean()->description('Whether the time is billable.'),
        ];
    }
}

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

#[Description('Split one stopped time entry, by id, across tasks in one go: the parts run end to end from its start, in the order given, and may add up to its minutes or less. The minutes they leave stay on the original entry (its task and description, after the parts) unless drop_rest is true; with nothing left, the original becomes the first part. Refused with period_settled when the entry or any part lands in a closed month or under a finalized or sent report. Returns every resulting entry.')]
final class TimeSplitTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'time_split';

    public function handle(Request $request, TimeEntryEditor $editor): Response
    {
        $validated = $request->validate([
            'entry' => ['required', 'regex:/^\d+$/'],
            'parts' => ['required', 'array', 'list'],
            'parts.*' => ['array'],
            'parts.*.task' => ['required', 'string'],
            'parts.*.minutes' => ['required', 'integer'],
            'parts.*.description' => ['nullable', 'string'],
            'drop_rest' => ['nullable', 'boolean'],
        ], [
            'entry.required' => 'Pass the time entry id.',
            'entry.regex' => 'Pass the time entry id as a number.',
            'parts.required' => 'Pass the parts: each a task and its minutes.',
        ]);

        try {
            return $this->payload($editor->split(
                $this->identity()->account->id,
                (string) $validated['entry'],
                array_values($validated['parts']),
                (bool) ($validated['drop_rest'] ?? false),
            ));
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
            'parts' => $schema->array()->items($schema->object([
                'task' => $schema->string()->description('Task id or name fragment.')->required(),
                'minutes' => $schema->integer()->description('Minutes for this part, 1 or more.')->required(),
                'description' => $schema->string()->description('What the time was spent on; defaults to the task name.'),
            ]))->description('The parts in order, laid end to end from the entry\'s start.')->required(),
            'drop_rest' => $schema->boolean()->description('Stop logging the minutes the parts leave instead of keeping them on the original entry.')->default(false),
        ];
    }
}

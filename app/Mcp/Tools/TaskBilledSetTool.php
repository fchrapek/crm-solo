<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\BilledTimeEditor;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Set a task\'s billed hours for a month (what the client is charged for it, e.g. "billable 3h"), or "clear" them so it bills its real time again. Exactly one of "hours" and "clear". The month\'s billed total is the sum of its tasks and follows at once, a draft report included. Real time entries are never changed; manual time the owner states goes in with log_time first. Refusals: not_reportable, period_settled, not_a_draft. Say what you will write and wait for the owner before calling it.')]
final class TaskBilledSetTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'task_billed_set';

    public function handle(Request $request, BilledTimeEditor $editor): Response
    {
        $v = $request->validate([
            'task' => ['required', 'string'],
            'month' => ['sometimes', 'nullable', 'string'],
            'hours' => ['sometimes', 'nullable', 'numeric'],
            'clear' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        try {
            return $this->payload($editor->taskSet(
                $this->identity()->account,
                (string) $v['task'],
                $v['month'] ?? null,
                isset($v['hours']) ? (float) $v['hours'] : null,
                (bool) ($v['clear'] ?? false),
                $v['note'] ?? null,
            ));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id or name fragment.')->required(),
            'month' => $schema->string()->description('The month as YYYY-MM; default the month the task was last worked in.'),
            'hours' => $schema->number()->description('Billed hours for the task, 0 or more.'),
            'clear' => $schema->boolean()->description('Drop the figure; the task bills its real time again.'),
            'note' => $schema->string()->description('Internal note in Polish, never shown to the client; omit to keep the stored one.'),
        ];
    }
}

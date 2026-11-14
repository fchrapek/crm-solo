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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('One task\'s two values for a month: "real" (the time logged on it, never rounded) and "billed" (what the client is charged: the "stated" figure the owner gave, else exactly the real time), whether the task is "reportable" (time on a task that is not reportable counts nowhere), the month\'s totals, figures stated in "other_months", and "refusal" saying why the figure cannot change now. Default month: the one the task was last worked in. Reads only.')]
final class TaskBilledTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'task_billed';

    public function handle(Request $request, BilledTimeEditor $editor): Response
    {
        $v = $request->validate([
            'task' => ['required', 'string'],
            'month' => ['sometimes', 'nullable', 'string'],
        ]);

        try {
            return $this->payload($editor->taskRead($this->identity()->account, (string) $v['task'], $v['month'] ?? null));
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
        ];
    }
}

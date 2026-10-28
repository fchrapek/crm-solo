<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\DayPlan;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Take a task off today\'s or tomorrow\'s plan. A name fragment matches only the tasks picked for that day. A closed day takes no change until it is reopened in the app. Returns the day\'s plan.')]
final class DayUnplanTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'day_unplan';

    public function handle(Request $request, DayPlan $plans): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'string'],
            'day' => ['nullable', 'string'],
        ], [
            'task.required' => 'Pass the task id or a fragment of a picked task\'s name.',
        ]);

        $account = $this->identity()->account->id;

        try {
            return $this->payload($plans->unplan($account, $validated['task'], $plans->day($validated['day'] ?? null)));
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
            'task' => $schema->string()->description('Task id, or a fragment of a picked task\'s name.')->required(),
            'day' => $schema->string()->description('today (default), tomorrow, or the date as YYYY-MM-DD.'),
        ];
    }
}

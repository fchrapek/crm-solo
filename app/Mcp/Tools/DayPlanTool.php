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

#[Description('Plan an open task for any day from today to the end of next week, as on the day screen: at most 5 tasks a day, and a closed day takes no change until it is reopened in the app. Returns the day\'s plan. "today" returns both days\' plans without changing them.')]
final class DayPlanTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'day_plan';

    public function handle(Request $request, DayPlan $plans): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'string'],
            'day' => ['nullable', 'string'],
        ], [
            'task.required' => 'Pass an open task id or a fragment of its name. To read the plan, call "today".',
        ]);

        $account = $this->identity()->account->id;

        try {
            return $this->payload($plans->plan($account, $validated['task'], $plans->day($validated['day'] ?? null)));
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
            'task' => $schema->string()->description('Open task id, or a fragment of its name.')->required(),
            'day' => $schema->string()->description('today (default), tomorrow, or a date up to the end of next week as YYYY-MM-DD.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TimerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Start a live timer on a task. Timers may overlap, so any already-running timer is reported back rather than blocking the new one. Close it with timer_stop.')]
final class TimerStartTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'timer_start';

    public function handle(Request $request, CrmEntityResolver $resolver, TimerService $timers): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'billable' => ['nullable', 'boolean'],
        ], [
            'task.required' => 'Pass a task id or a fragment of the task name.',
        ]);

        $accountId = $this->identity()->account->id;

        try {
            $task = $resolver->task($validated['task'], $accountId, openOnly: true);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        $open = $timers->open($accountId);

        $entry = $timers->start(
            $task,
            $validated['description'] ?? null,
            $validated['billable'] ?? true,
        );

        return $this->payload($timers->startPayload($entry, $task, $open));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('Task id, or a fragment of the task name.')
                ->required(),

            'description' => $schema->string()
                ->description('What is being worked on. Defaults to the task name.'),

            'billable' => $schema->boolean()
                ->description('Whether the time is billable.')
                ->default(true),
        ];
    }
}

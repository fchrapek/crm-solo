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

#[Description('Stop a running timer, setting its end time and duration. With exactly one timer running the entry argument can be omitted; with several, pass the entry id or a task-name fragment.')]
final class TimerStopTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'timer_stop';

    public function handle(Request $request, CrmEntityResolver $resolver, TimerService $timers): Response
    {
        $validated = $request->validate([
            'entry' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            $entry = $resolver->openTimeEntry($this->identity()->account->id, $validated['entry'] ?? null);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        $entry = $timers->stop($entry, $validated['description'] ?? null);

        return $this->payload($timers->stopPayload($entry));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'entry' => $schema->string()
                ->description('Time-entry id, or a fragment of the task name. Omit when only one timer is running.'),

            'description' => $schema->string()
                ->description('Replaces the description on the entry as it closes.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\MonthCloseStep;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\MonthCloseTicker;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Move one step of a client month-close checklist to done, skipped or pending. Site steps repeat once per site, so pass "project" when the client runs more than one. Starts the run for that period if it has not been started yet, and returns the full checklist after the move.')]
final class MonthCloseTickTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'month_close_tick';

    public function handle(Request $request, CrmEntityResolver $resolver, MonthCloseTicker $ticker): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'step' => ['required', 'string'],
            'state' => ['required', 'string'],
            'project' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
            'period' => ['nullable', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
            'step.required' => 'Pass the step key, for example "wp_updates". Call month_close_status first to see the keys for this client.',
            'state.required' => 'Pass the new state: done, skipped or pending.',
        ]);

        try {
            $client = $resolver->client($validated['client'], $this->identity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        try {
            return $this->payload($ticker->tick(
                $client,
                $validated['period'] ?? $ticker->defaultPeriod(),
                $validated['step'],
                $validated['state'],
                $this->identity()->user,
                $validated['project'] ?? null,
                $validated['note'] ?? null,
            ));
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e->getMessage());
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()
                ->description('Client id, or a fragment of the client name.')
                ->required(),

            'step' => $schema->string()
                ->description('Checklist step key, for example "wp_updates" or "report". month_close_status lists the keys for this client.')
                ->required(),

            'project' => $schema->string()
                ->description('Site id or name fragment. Site steps repeat once per site, so this is required whenever a client runs more than one site; the error lists the sites when it is missing.'),

            'state' => $schema->string()
                ->enum([MonthCloseStep::STATE_DONE, MonthCloseStep::STATE_SKIPPED, MonthCloseStep::STATE_PENDING])
                ->description('The new state for that step. Use pending to reopen a step.')
                ->required(),

            'note' => $schema->string()
                ->description('Why. Worth recording whenever a step is skipped, so the reason survives to the next run.'),

            'period' => $schema->string()
                ->description('Billed month as YYYY-MM. Defaults to the previous month.'),
        ];
    }
}

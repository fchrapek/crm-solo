<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\Lead;
use App\Services\Agent\ReferenceNotFoundException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Move a lead to another funnel stage, recording the hop in the append-only stage history that the funnel conversion rates are measured from.')]
final class LeadStageTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'lead_stage';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'lead' => ['required', 'integer'],
            'stage' => ['required', 'string'],
            'note' => ['nullable', 'string'],
        ], [
            'lead.required' => 'Pass the numeric lead id — today lists hot leads with their ids.',
            'stage.required' => 'Pass the stage to move the lead to.',
        ]);

        $lead = Lead::query()->where('account_id', $this->identity()->account->id)->find($validated['lead']);

        if ($lead === null) {
            return $this->referenceError(new ReferenceNotFoundException('lead', (string) $validated['lead']));
        }

        try {
            $lead->transitionTo($validated['stage'], $validated['note'] ?? null, $this->identity()->user);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e->getMessage(), [
                'valid_stages' => Lead::rowStages((string) $lead->pipeline),
            ]);
        }

        return $this->payload([
            'id' => $lead->id,
            'stage' => $lead->stage,
            'is_won' => $lead->isWon(),
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'lead' => $schema->integer()
                ->description('Numeric lead id.')
                ->required(),

            'stage' => $schema->string()
                ->description('Stage slug to move the lead to. An invalid stage comes back with the valid ones listed.')
                ->required(),

            'note' => $schema->string()
                ->description('Why the lead moved. Stored on the stage-history entry.'),
        ];
    }
}

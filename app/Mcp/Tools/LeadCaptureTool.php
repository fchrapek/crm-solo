<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\Lead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Capture a new lead in the funnel. The source is permanent channel attribution set once at capture, so an unknown slug is rejected rather than guessed.')]
final class LeadCaptureTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'lead_capture';

    public function handle(Request $request): Response
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string'],
                'source' => ['required', 'string'],
                'pipeline' => ['nullable', 'string'],
                'email' => ['nullable', 'string'],
                'phone' => ['nullable', 'string'],
                'company' => ['nullable', 'string'],
                'note' => ['nullable', 'string'],
            ], [
                'name.required' => 'Pass the lead name.',
                'source.required' => 'Pass the source slug — it is permanent channel attribution. Valid sources: '.implode(', ', Lead::sources()),
            ]);
        } catch (ValidationException $e) {
            return $this->invalidArgument(
                implode(' ', $e->validator->errors()->all()),
                ['valid_sources' => Lead::sources()],
            );
        }

        try {
            $lead = Lead::create([
                'account_id' => $this->identity()->account->id,
                'pipeline' => $validated['pipeline'] ?? (Lead::pipelines()[0] ?? ''),
                'name' => mb_trim($validated['name']),
                'source' => mb_trim($validated['source']),
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'company' => $validated['company'] ?? null,
                'notes' => $validated['note'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgument($e->getMessage(), [
                'valid_sources' => Lead::sources(),
                'valid_pipelines' => Lead::pipelines(),
            ]);
        }

        return $this->payload([
            'id' => $lead->id,
            'pipeline' => $lead->pipeline,
            'stage' => $lead->stage,
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Who the lead is.')
                ->required(),

            'source' => $schema->string()
                ->enum(Lead::sources())
                ->description('Where the lead came from. Permanent attribution — it cannot be changed later.')
                ->required(),

            'pipeline' => $schema->string()
                ->enum(Lead::pipelines())
                ->description('Which brand funnel the lead belongs to. Defaults to the first configured pipeline.'),

            'email' => $schema->string()->description('Contact email.'),
            'phone' => $schema->string()->description('Contact phone.'),
            'company' => $schema->string()->description('Company name.'),
            'note' => $schema->string()->description('Free-text notes stored on the lead.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description("Append a journal entry to a client's timeline — the same composer the Activity tab writes to. Use it to record calls, decisions and anything worth finding again later.")]
final class AddNoteTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'add_note';

    public function handle(Request $request, CrmEntityResolver $resolver): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'note' => ['required', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
            'note.required' => 'Pass the note text to record on the timeline.',
        ]);

        $identity = $this->identity();

        try {
            $client = $resolver->client($validated['client'], $identity->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        $note = mb_trim($validated['note']);

        if ($note === '') {
            return $this->invalidArgument('The note is empty.');
        }

        // Same-stage transition with a note is how the timeline records a plain
        // journal entry; the acting user is what the UI shows as the author.
        $event = $client->transitionTo($client->lifecycle_stage, $note, $identity->user);

        return $this->payload([
            'client_id' => $client->id,
            'event_id' => $event?->id,
        ]);
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

            'note' => $schema->string()
                ->description('The journal entry text.')
                ->required(),
        ];
    }
}

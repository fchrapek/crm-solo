<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ClientIntake;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a client with the basics: name, type (retainer or one_off), an optional contact person with email and phone, NIP and a note. Retainer terms are set in the app. A name close to an existing client is refused with "possible_duplicate" and the matches: show them to the user, and pass force only once the user confirms it is a different company. Confirm the details with the user before calling.')]
final class ClientCreateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_create';

    public function handle(Request $request, ClientIntake $intake): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'type' => ['nullable', 'string'],
            'contact' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'nip' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'force' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Pass the client (company) name.',
        ]);

        try {
            return $this->payload($intake->create($this->identity()->account->id, $validated));
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
            'name' => $schema->string()->description('Client (company) name.')->required(),
            'type' => $schema->string()->enum(['retainer', 'one_off'])->description('retainer (monthly maintenance) or one_off (a single order).'),
            'contact' => $schema->string()->description('Contact person, "First Last".'),
            'email' => $schema->string()->description('Email address.'),
            'phone' => $schema->string()->description('Phone number.'),
            'nip' => $schema->string()->description('Polish tax id (NIP).'),
            'note' => $schema->string()->description('A note on the client.'),
            'force' => $schema->boolean()->description('Create it even when a client with a close name exists. Only after the user confirms.'),
        ];
    }
}

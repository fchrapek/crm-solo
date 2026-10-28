<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\Client;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ClientDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List clients with their type (retainer, one_off, hourly, project), stage, billing mode and a summary of today\'s retainer positions. Search matches the name, email, phone or NIP. Use it to find a client before creating one.')]
final class ClientsTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'clients';

    public function handle(Request $request, ClientDirectory $clients): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string'],
            'stage' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'archived' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer'],
        ]);

        try {
            return $this->payload($clients->list($this->identity()->account->id, $validated));
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
            'search' => $schema->string()->description('Part of the name, email, phone or NIP.'),
            'stage' => $schema->string()->enum(Client::LIFECYCLE_STAGES)->description('Only clients at this stage.'),
            'type' => $schema->string()->enum(Client::COOPERATION_TYPES)->description('Only clients of this type.'),
            'archived' => $schema->boolean()->description('Include archived clients.'),
            'limit' => $schema->integer()->description('At most this many, default 100, up to 500.'),
        ];
    }
}

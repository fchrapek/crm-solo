<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Clients\Fields\ClientFieldWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Assign a client to the user of the account responsible for it, or to nobody with "none".')]
final class ClientAssignTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_assign';

    public function handle(Request $request, CrmEntityResolver $resolver, ClientFieldWriter $writer): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'user' => ['required', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
            'user.required' => 'Pass a user id, email or name fragment, or "none".',
        ]);

        $identity = $this->identity();

        try {
            $client = $resolver->client($validated['client'], $identity->account->id);
            $user = $validated['user'] === 'none' ? null : $resolver->user($validated['user'], $identity->account->id);
            $changed = $writer->assign($client, $user, $identity->user);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['client_id' => $client->id, 'assignee_id' => $user?->id, 'changed' => $changed]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id, or a fragment of the client name.')->required(),
            'user' => $schema->string()->description('User id, email or a fragment of the name, or "none".')->required(),
        ];
    }
}

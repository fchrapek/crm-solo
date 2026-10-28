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

#[Description('Set custom fields of one client in one write: all change or none. Keys and option keys come from client_fields. null or an empty string clears a field. Dates are YYYY-MM-DD, date and time YYYY-MM-DD HH:MM in the CRM display timezone, multiselect a list of option keys.')]
final class ClientFieldSetTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_field_set';

    public function handle(Request $request, CrmEntityResolver $resolver, ClientFieldWriter $writer): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'values' => ['required', 'array'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
            'values.required' => 'Pass values as an object of field key => value.',
        ]);

        $identity = $this->identity();

        try {
            $client = $resolver->client($validated['client'], $identity->account->id);
            $changes = $writer->set($client, $validated['values'], $identity->user);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['client_id' => $client->id, 'changed' => $changes]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id, or a fragment of the client name.')->required(),
            'values' => $schema->object()->description('Field key => value, e.g. {"visit_status": "booked", "visit_at": "2026-10-20 10:00"}.')->required(),
        ];
    }
}

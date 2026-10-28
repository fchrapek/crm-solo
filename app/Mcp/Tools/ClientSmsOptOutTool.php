<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Messaging\MessageSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Stop text messages to a client who asked for none (opt_out true), or allow them again (false).')]
final class ClientSmsOptOutTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_sms_opt_out';

    public function handle(Request $request, CrmEntityResolver $resolver, MessageSettings $settings): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
            'opt_out' => ['nullable', 'boolean'],
        ], ['client.required' => 'Pass a client id or a fragment of the client name.']);

        try {
            $client = $resolver->client($validated['client'], $this->identity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        $settings->optOut($client, (bool) ($validated['opt_out'] ?? true));

        return $this->payload(['client_id' => $client->id, 'sms_opt_out' => (bool) $client->sms_opt_out]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id, or a fragment of the client name.')->required(),
            'opt_out' => $schema->boolean()->description('true stops messages, false allows them again.')->default(true),
        ];
    }
}

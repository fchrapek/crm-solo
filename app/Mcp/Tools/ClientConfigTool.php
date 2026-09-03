<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\ClientConfigData;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description("A client's month-close configuration: cohort, report mode, ssh target, backup path, local repository and invoicing defaults.")]
final class ClientConfigTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_config';

    public function handle(Request $request, CrmEntityResolver $resolver, ClientConfigData $config): Response
    {
        $validated = $request->validate([
            'client' => ['required', 'string'],
        ], [
            'client.required' => 'Pass a client id or a fragment of the client name.',
        ]);

        try {
            $client = $resolver->client($validated['client'], $this->identity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }

        return $this->payload($config->for($client));
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
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Models\ClientFieldDefinition;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The custom client fields this account defined: key, label, type (text, textarea, select, multiselect, date, datetime, url), options for select fields, whether a value is required. Read it before client_field_set to know the keys and option keys.')]
final class ClientFieldsTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_fields';

    public function handle(Request $request, ClientFieldDefinitions $definitions): Response
    {
        $validated = $request->validate(['all' => ['nullable', 'boolean']]);

        return $this->payload(['fields' => $definitions->list($this->identity()->account->id, (bool) ($validated['all'] ?? false))
            ->map(fn (ClientFieldDefinition $field): array => $field->toAgentArray())->values()->all()]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'all' => $schema->boolean()->description('Include archived fields.')->default(false),
        ];
    }
}

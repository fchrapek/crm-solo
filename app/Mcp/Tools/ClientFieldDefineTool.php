<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Clients\Fields\ClientFieldDefinitions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a custom client field, or change the label, options, required flag, overview place or archived state of one. The key and type of an existing field never change; option keys, once added, stay (labels may be renamed). Confirm the field with the user before calling.')]
final class ClientFieldDefineTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'client_field_define';

    public function handle(Request $request, ClientFieldDefinitions $definitions): Response
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'label' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
            'options.*.key' => ['required', 'string'],
            'options.*.label' => ['required', 'string'],
            'required' => ['nullable', 'boolean'],
            'on_overview' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
        ], [
            'key.required' => 'Pass the field key, e.g. visit_status.',
        ]);

        try {
            $field = $definitions->define($this->identity()->account->id, $validated['key'], $validated);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['field' => $field->toAgentArray()]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Field key: lowercase letters, digits and _, starting with a letter. It never changes.')->required(),
            'label' => $schema->string()->description('Label shown in the app. Required for a new field.'),
            'type' => $schema->string()->enum(['text', 'textarea', 'select', 'multiselect', 'date', 'datetime', 'url'])->description('Required for a new field; an existing field keeps its type.'),
            'options' => $schema->array()->items($schema->object([
                'key' => $schema->string()->description('Option key: lowercase letters, digits, _ and -.')->required(),
                'label' => $schema->string()->description('Option label.')->required(),
            ]))->description('For select and multiselect: new keys are added, known keys get the new label.'),
            'required' => $schema->boolean()->description('Values may not be cleared.'),
            'on_overview' => $schema->boolean()->description('true shows the field on the client\'s overview and in the client list.'),
            'archived' => $schema->boolean()->description('true hides the field (values stay), false brings it back.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\IntakeForms;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Change a website form\'s name, pipeline, source, origins (the list is replaced), notify-to address or active switch; only the fields given change. Never a secret: the Turnstile and webhook secrets are set by the owner on the server. Refusals: "invalid_argument" with the fields, or for a form id outside the account. Confirm the change with the user before calling.')]
final class IntakeFormEditTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'intake_form_edit';

    public function handle(Request $request, IntakeForms $forms): Response
    {
        $validated = $request->validate([
            'form' => ['required', 'integer'],
            'name' => ['nullable', 'string'],
            'pipeline' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'origins' => ['nullable', 'array'],
            'origins.*' => ['string'],
            'notify_to' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        try {
            return $this->payload(['form' => $forms->edit($this->identity()->account->id, (int) $validated['form'], $validated)]);
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
            'form' => $schema->integer()->description('The form id, from intake_forms.')->required(),
            'name' => $schema->string()->description('A new name.'),
            'pipeline' => $schema->string()->description('An existing lead pipeline slug.'),
            'source' => $schema->string()->description('A lead source slug valid for the account.'),
            'origins' => $schema->array()->items($schema->string())->description('Bare lowercase origins, replacing the list.'),
            'notify_to' => $schema->string()->description('Address the lead notice goes to.'),
            'active' => $schema->boolean()->description('Whether the form takes posts.'),
        ];
    }
}

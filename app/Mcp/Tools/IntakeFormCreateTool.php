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

#[Description('Create a website form that posts leads to the CRM: a name, an existing pipeline, a source the account knows, the bare origins its site posts from (https://www.example.test; plain http only for localhost) and the address lead notices go to. Returns the form with its endpoint. A browser form also needs a Turnstile secret, and a server a webhook secret: the owner sets both on the server, never through a tool. Refusals: "invalid_argument" with the fields. Confirm the settings with the user before calling.')]
final class IntakeFormCreateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'intake_form_create';

    public function handle(Request $request, IntakeForms $forms): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'pipeline' => ['required', 'string'],
            'source' => ['required', 'string'],
            'origins' => ['required', 'array'],
            'origins.*' => ['string'],
            'notify_to' => ['nullable', 'string'],
        ]);

        try {
            return $this->payload(['form' => $forms->create($this->identity()->account->id, $validated)]);
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
            'name' => $schema->string()->description('What the form is, e.g. "Contact drawer".')->required(),
            'pipeline' => $schema->string()->description('An existing lead pipeline slug.')->required(),
            'source' => $schema->string()->description('A lead source slug valid for the account.')->required(),
            'origins' => $schema->array()->items($schema->string())->description('Bare lowercase origins the site posts from.')->required(),
            'notify_to' => $schema->string()->description('Address the lead notice goes to.'),
        ];
    }
}

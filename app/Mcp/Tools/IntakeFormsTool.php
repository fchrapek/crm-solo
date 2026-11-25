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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the account\'s website forms that post leads: id, name, endpoint, allowed origins, pipeline, source, notify-to address, active switch and the lead notices waiting, failed, sent and skipped. The Turnstile and webhook secrets show only as set or not; no tool takes or returns a secret.')]
final class IntakeFormsTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'intake_forms';

    public function handle(Request $request, IntakeForms $forms): Response
    {
        try {
            return $this->payload($forms->list($this->identity()->account->id));
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

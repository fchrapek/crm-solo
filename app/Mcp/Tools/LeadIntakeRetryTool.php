<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Leads\LeadNotices;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Queue the account\'s failed and waiting lead notices of the last 7 days again, for example after the owner fixed the sender\'s password; older ones are skipped. Needs a sender (see mail_sender). Returns how many went back to waiting, were queued and were skipped.')]
final class LeadIntakeRetryTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'lead_intake_retry';

    public function handle(Request $request, LeadNotices $notices): Response
    {
        try {
            return $this->payload($notices->reconcile($this->identity()->account->id, true));
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

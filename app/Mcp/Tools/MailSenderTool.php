<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\Sender\AccountSenders;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show the account\'s own mail sender for lead notices: whether one is set, host, port, login, from address and name, the switch, and the last test send (when, ok, reason code). The password shows only as set and readable or not; it is set by the owner on the server, never through a tool.')]
final class MailSenderTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'mail_sender';

    public function handle(Request $request, AccountSenders $senders): Response
    {
        try {
            return $this->payload(['sender' => $senders->describe($this->identity()->account->id)]);
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

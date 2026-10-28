<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Messaging\MessageSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Send a failed text message again by id (from messages): it is queued at once and its recipient and text are rebuilt from the client as it is now. A failure without an answer from the provider may already have reached the phone, so confirm with the user first.')]
final class MessageResendTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'message_resend';

    public function handle(Request $request, MessageSettings $settings): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer']], ['id.required' => 'Pass the message id.']);
        $identity = $this->identity();

        try {
            $message = $settings->resend($identity->account->id, (int) $validated['id']);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['id' => $message->id, 'status' => $message->status, 'resends' => $message->resends, 'previous_error' => $message->previous_error]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Message id.')->required(),
        ];
    }
}

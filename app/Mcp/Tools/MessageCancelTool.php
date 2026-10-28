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

#[Description('Cancel a pending or failed text message by id (from messages); it never goes out later. Confirm with the user first.')]
final class MessageCancelTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'message_cancel';

    public function handle(Request $request, MessageSettings $settings): Response
    {
        $validated = $request->validate(['id' => ['required', 'integer']], ['id.required' => 'Pass the message id.']);
        $identity = $this->identity();

        try {
            $message = $settings->cancel($identity->account->id, (int) $validated['id'], $identity->user);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['id' => $message->id, 'status' => $message->status]);
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

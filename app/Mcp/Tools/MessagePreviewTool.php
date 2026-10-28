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

#[Description('Preview a text message template as a client would get it: placeholders filled from one of the account\'s clients (an empty one stays in braces), Polish letters handled as the account sends them, and the character and SMS count. Saves nothing.')]
final class MessagePreviewTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'message_preview';

    public function handle(Request $request, MessageSettings $settings): Response
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:1000']], ['body.required' => 'Pass the template text.']);

        try {
            $preview = $settings->preview($this->identity()->account->id, $validated['body']);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['preview' => [...$preview, 'untrusted' => ['text', 'client']]]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->description('Template text; placeholders {client_name}, {assignee_name}, {field:key}.')->required(),
        ];
    }
}

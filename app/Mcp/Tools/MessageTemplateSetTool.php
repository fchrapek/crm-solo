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

#[Description('Add, change or delete (delete: true; refused while a rule uses it) a text message template. The body may use {client_name}, {assignee_name} and {field:key} with keys from client_fields; a placeholder without a value at sending time skips the message. Confirm the text with the user before calling.')]
final class MessageTemplateSetTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'message_template_set';

    public function handle(Request $request, MessageSettings $settings): Response
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'delete' => ['nullable', 'boolean'],
        ], ['key.required' => 'Pass the template key, e.g. visit_booked.']);

        if ($validated['delete'] ?? false) {
            try {
                $settings->deleteTemplate($this->identity()->account->id, $validated['key']);
            } catch (AgentRefusal $e) {
                return $this->refusalError($e);
            }

            return $this->payload(['deleted' => $validated['key']]);
        }

        try {
            $template = $settings->template($this->identity()->account->id, $validated['key'], $validated['name'] ?? null, $validated['body'] ?? null);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['template' => ['key' => $template->key, 'name' => $template->name, 'body' => $template->body, 'untrusted' => ['name', 'body']]]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Template key: lowercase letters, digits and _.')->required(),
            'name' => $schema->string()->description('Name shown in the app. Required for a new template.'),
            'body' => $schema->string()->description('Message text. Required for a new template.'),
            'delete' => $schema->boolean()->description('true deletes the template.'),
        ];
    }
}

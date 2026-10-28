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

#[Description('Show or change how text messages go out: quiet hours (local HH:MM; a message due inside them waits for the end) and ascii (true spells Polish letters without diacritics, so one SMS holds 160 characters instead of 70) and provider (simulator sends nothing; smsapi sends real messages and needs SMSAPI connected in Integrations in the app). Call without arguments to read. Confirm a change with the user first.')]
final class MessageSendingTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'message_sending';

    public function handle(Request $request, MessageSettings $settings): Response
    {
        $validated = $request->validate([
            'quiet_from' => ['nullable', 'string'],
            'quiet_to' => ['nullable', 'string'],
            'ascii' => ['nullable', 'boolean'],
            'provider' => ['nullable', 'string'],
        ]);

        try {
            $sending = $settings->sending($this->identity()->account->id, $validated['quiet_from'] ?? null, $validated['quiet_to'] ?? null, $validated['ascii'] ?? null, $validated['provider'] ?? null);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }

        return $this->payload(['sending' => $sending]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'quiet_from' => $schema->string()->description('Quiet hours start, local HH:MM.'),
            'quiet_to' => $schema->string()->description('Quiet hours end, local HH:MM.'),
            'ascii' => $schema->boolean()->description('true spells Polish letters without diacritics.'),
            'provider' => $schema->string()->enum(['simulator', 'smsapi'])->description('simulator or smsapi.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI;

interface AIProviderInterface
{
    /**
     * Send a simple chat completion request (no tools).
     *
     * @param  array  $messages  Array of ['role' => 'user'|'assistant', 'content' => '...']
     * @param  array  $options  Provider-specific options (max_tokens, temperature, etc.)
     */
    public function chat(string $systemPrompt, array $messages, array $options = []): string;

    /**
     * Send a chat completion request with tool/function calling support.
     *
     * @param  array  $messages  Conversation messages (may include tool_calls and tool results)
     * @param  array  $tools  Tool definitions in OpenAI function calling format
     * @param  array  $options  Provider-specific options
     * @return array{content: string|null, tool_calls: array}
     */
    public function chatWithTools(string $systemPrompt, array $messages, array $tools, array $options = []): array;

    public function getProviderName(): string;
}

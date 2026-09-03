<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAIProvider implements AIProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function chat(string $systemPrompt, array $messages, array $options = []): string
    {
        $result = $this->chatWithTools($systemPrompt, $messages, [], $options);

        return $result['content'] ?? '';
    }

    public function chatWithTools(string $systemPrompt, array $messages, array $tools, array $options = []): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $model = $options['model'] ?? config('services.openai.model', 'gpt-4o');

        $apiMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $msg) {
            $apiMessage = ['role' => $msg['role'], 'content' => $msg['content'] ?? ''];

            if (isset($msg['tool_calls'])) {
                $apiMessage['tool_calls'] = $msg['tool_calls'];
                // OpenAI requires content to be null when tool_calls present
                if (empty($apiMessage['content'])) {
                    $apiMessage['content'] = null;
                }
            }

            if (isset($msg['tool_call_id'])) {
                $apiMessage['tool_call_id'] = $msg['tool_call_id'];
            }

            $apiMessages[] = $apiMessage;
        }

        $payload = [
            'model' => $model,
            'messages' => $apiMessages,
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post(self::API_URL, $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI API request failed: '.$response->body());
        }

        $choice = $response->json('choices.0.message');

        return [
            'content' => $choice['content'] ?? null,
            'tool_calls' => $choice['tool_calls'] ?? [],
        ];
    }

    public function getProviderName(): string
    {
        return 'openai';
    }
}

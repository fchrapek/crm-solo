<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ClaudeProvider implements AIProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function chat(string $systemPrompt, array $messages, array $options = []): string
    {
        $result = $this->chatWithTools($systemPrompt, $messages, [], $options);

        return $result['content'] ?? '';
    }

    public function chatWithTools(string $systemPrompt, array $messages, array $tools, array $options = []): array
    {
        $apiKey = config('services.anthropic.api_key');

        if (! $apiKey) {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        $model = $options['model'] ?? config('services.anthropic.model', 'claude-sonnet-4-20250514');

        // Convert messages to Anthropic format
        $apiMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'tool') {
                // Anthropic uses tool_result content blocks
                $apiMessages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $msg['tool_call_id'],
                            'content' => $msg['content'],
                        ],
                    ],
                ];
            } elseif (isset($msg['tool_calls']) && ! empty($msg['tool_calls'])) {
                // Convert OpenAI-style tool_calls to Anthropic tool_use blocks
                $content = [];
                if (! empty($msg['content'])) {
                    $content[] = ['type' => 'text', 'text' => $msg['content']];
                }
                foreach ($msg['tool_calls'] as $tc) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'],
                        'name' => $tc['function']['name'],
                        'input' => json_decode($tc['function']['arguments'], true) ?? [],
                    ];
                }
                $apiMessages[] = ['role' => 'assistant', 'content' => $content];
            } else {
                $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content'] ?? ''];
            }
        }

        $payload = [
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => $apiMessages,
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        // Convert OpenAI tool format to Anthropic
        if (! empty($tools)) {
            $payload['tools'] = array_map(fn ($tool) => [
                'name' => $tool['function']['name'],
                'description' => $tool['function']['description'],
                'input_schema' => $tool['function']['parameters'],
            ], $tools);
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout(60)
            ->post(self::API_URL, $payload);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API request failed: '.$response->body());
        }

        $data = $response->json();

        // Parse Anthropic response into normalized format
        $content = null;
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content = ($content ?? '').$block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'],
                        'arguments' => json_encode($block['input']),
                    ],
                ];
            }
        }

        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];
    }

    public function getProviderName(): string
    {
        return 'claude';
    }
}

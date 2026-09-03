<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

final class AIServiceManager
{
    private array $providers = [];

    public function getProvider(?string $provider = null): AIProviderInterface
    {
        $provider ??= config('services.ai.default_provider', 'openai');

        return $this->providers[$provider] ??= match ($provider) {
            'openai' => new OpenAIProvider,
            'claude' => new ClaudeProvider,
            default => throw new InvalidArgumentException("Unknown AI provider: {$provider}"),
        };
    }
}

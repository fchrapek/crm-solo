<?php

declare(strict_types=1);

namespace App\Services\TaskSources;

use RuntimeException;

/**
 * Registry of external task-source providers, keyed by provider string
 * ('trello', …). Mirrors ReportComposerRegistry: providers are registered in
 * AppServiceProvider and resolved by key at the call site.
 */
final class TaskSourceRegistry
{
    /**
     * @var array<string, TaskSourceProviderInterface>
     */
    private array $providers = [];

    public function register(TaskSourceProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): TaskSourceProviderInterface
    {
        if (! isset($this->providers[$key])) {
            throw new RuntimeException("Task source provider '{$key}' is not registered.");
        }

        return $this->providers[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return array<string, TaskSourceProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}

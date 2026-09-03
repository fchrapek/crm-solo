<?php

declare(strict_types=1);

namespace App\Services\Reports;

use RuntimeException;

final class ReportComposerRegistry
{
    /**
     * @var array<string, ReportComposerInterface>
     */
    private array $composers = [];

    private ?string $defaultKey = null;

    public function register(ReportComposerInterface $composer, bool $default = false): void
    {
        $this->composers[$composer->key()] = $composer;

        if ($default || $this->defaultKey === null) {
            $this->defaultKey = $composer->key();
        }
    }

    public function get(string $key): ReportComposerInterface
    {
        if (! isset($this->composers[$key])) {
            throw new RuntimeException("Report composer '{$key}' is not registered.");
        }

        return $this->composers[$key];
    }

    /**
     * The composer for a key, falling back to the default when the key is not
     * one we can run. Reports written by hand carry keys like 'manual' that no
     * composer backs; regenerating one should compose afresh rather than 500.
     */
    public function getOrDefault(?string $key): ReportComposerInterface
    {
        return $key !== null && isset($this->composers[$key])
            ? $this->composers[$key]
            : $this->default();
    }

    public function default(): ReportComposerInterface
    {
        if ($this->defaultKey === null) {
            throw new RuntimeException('No report composers are registered.');
        }

        return $this->composers[$this->defaultKey];
    }

    /**
     * @return array<string, ReportComposerInterface>
     */
    public function all(): array
    {
        return $this->composers;
    }
}

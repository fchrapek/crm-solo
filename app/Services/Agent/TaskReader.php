<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;

/**
 * Opening a task for an agent: bring the card's details up to date when they
 * are stale (or when asked), then build the record. The CLI verb, the MCP
 * tool and the MCP resource all read through here.
 */
final class TaskReader
{
    public function __construct(
        private readonly CardDetailsFetcher $fetcher,
        private readonly TaskRecord $record,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(Task $task, bool $refresh = false): array
    {
        $error = $this->fetcher->refresh($task, $refresh);

        return $this->record->build($task->fresh() ?? $task, $error);
    }
}

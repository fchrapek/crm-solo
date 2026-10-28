<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Services\Agent\CardDetailsFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings a card's checklists, comments and files up to date after its task
 * page was opened, so the page shows the cache at once and picks the fresh
 * copy up on its next poll. The fetcher's staleness, backoff and lock apply.
 */
final class RefreshTaskCardDetailsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = CardDetailsFetcher::LOCK_SECONDS;

    public int $uniqueFor = CardDetailsFetcher::LOCK_SECONDS;

    public function __construct(public readonly int $taskId) {}

    public function uniqueId(): string
    {
        return (string) $this->taskId;
    }

    public function handle(CardDetailsFetcher $fetcher): void
    {
        $task = Task::query()->with('cardDetails')->find($this->taskId);
        if ($task !== null) {
            $fetcher->refresh($task);
        }
    }
}

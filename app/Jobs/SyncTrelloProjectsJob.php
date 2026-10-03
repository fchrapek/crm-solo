<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\SyncCompleted;
use App\Models\Integration;
use App\Services\TaskSources\TaskSourceRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncTrelloProjectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    public function __construct(
        private readonly Integration $integration,
        private readonly ?string $uuid = null,
    ) {}

    public function handle(TaskSourceRegistry $taskSources): void
    {
        // A job queued before the demo lost its integrations, or retrying, must not reach Trello.
        if (config('app.demo')) {
            return;
        }

        $stats = $taskSources->get('trello')->syncAll($this->integration);

        $this->integration->update([
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);

        Log::info('Trello sync completed', [
            'account_id' => $this->integration->account_id,
            'stats' => $stats,
        ]);

        if ($this->uuid) {
            SyncCompleted::dispatch($this->uuid, 'success', __('Trello sync completed successfully.'));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $errorMessage = __('Trello sync failed. Please check your API token in integration settings.');

        $this->integration->update([
            'last_sync_error' => $errorMessage,
        ]);

        Log::error('Trello sync failed', [
            'account_id' => $this->integration->account_id,
            'error' => $exception?->getMessage(),
        ]);

        if ($this->uuid) {
            SyncCompleted::dispatch($this->uuid, 'error', $errorMessage);
        }
    }
}

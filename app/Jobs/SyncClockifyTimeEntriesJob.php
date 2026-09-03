<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\SyncCompleted;
use App\Models\Integration;
use App\Services\Integrations\Clockify\ClockifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncClockifyTimeEntriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    public function __construct(
        private readonly Integration $integration,
        private readonly ?string $afterDate = null,
        private readonly ?string $uuid = null,
    ) {}

    public function handle(): void
    {
        $service = new ClockifyService($this->integration);
        $stats = $service->syncTimeEntries($this->integration->account_id, $this->afterDate);

        $this->integration->update([
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);

        Log::info('Clockify sync completed', [
            'account_id' => $this->integration->account_id,
            'stats' => $stats,
        ]);

        if ($this->uuid) {
            SyncCompleted::dispatch($this->uuid, 'success', __('Clockify sync completed successfully.'));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $errorMessage = __('Clockify sync failed. Please check your API key.');

        $this->integration->update([
            'last_sync_error' => $errorMessage,
        ]);

        Log::error('Clockify sync failed', [
            'account_id' => $this->integration->account_id,
            'error' => $exception?->getMessage(),
        ]);

        if ($this->uuid) {
            SyncCompleted::dispatch($this->uuid, 'error', $errorMessage);
        }
    }
}

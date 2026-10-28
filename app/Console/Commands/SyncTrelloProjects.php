<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Jobs\SyncTrelloProjectsJob;
use App\Models\Integration;
use App\Services\Integrations\Trello\TrelloService;
use App\Services\TaskSources\TaskSourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[AccountScope(AccountScope::OPERATOR)]
final class SyncTrelloProjects extends Command
{
    private const int SLOW_RUN_SECONDS = 60;

    private const int HEAVY_RUN_MB = 150;

    protected $signature = 'trello:sync
                            {--account= : The account ID to sync (syncs all if not specified)}
                            {--force : Skip the confirmation prompt}
                            {--sync : Run synchronously instead of dispatching a job}';

    protected $description = 'Sync projects and tasks from connected Trello boards (operator: every account with Trello, or the one named by --account)';

    public function handle(TaskSourceRegistry $taskSources): int
    {
        $accountId = $this->option('account');
        $force = $this->option('force');
        $sync = $this->option('sync');

        $query = Integration::where('provider', 'trello')
            ->where('is_enabled', true);

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $integrations = $query->get()->filter(fn ($i) => $i->hasValidApiKey());

        if ($integrations->isEmpty()) {
            $this->error('No enabled Trello integrations found.');

            return self::FAILURE;
        }

        if (! $force) {
            $this->info("Found {$integrations->count()} Trello integration(s) to sync.");

            if (! $this->confirm('Do you want to continue?')) {
                $this->info('Sync cancelled.');

                return self::SUCCESS;
            }
        }

        $started = now();
        // One deadline for the whole run, every account and board, so it ends inside the scheduler's overlap lock.
        $deadline = TrelloService::deadline();

        foreach ($integrations as $integration) {
            if ($sync && TrelloService::outOfTime($deadline)) {
                $this->warn("Out of time: account {$integration->account_id} and the rest wait for the next run.");

                break;
            }
            $this->info("Processing account ID: {$integration->account_id}");

            if ($sync) {
                $stats = $taskSources->get('trello')->syncAll($integration, $deadline);

                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Boards', $stats['boards']],
                        ['Tasks Created', $stats['created']],
                        ['Tasks Updated', $stats['updated']],
                        ['Skipped (not adopted)', $stats['skipped'] ?? 0],
                        ['Errors', $stats['errors']],
                    ]
                );
            } else {
                SyncTrelloProjectsJob::dispatch($integration);
                $this->info('  → Job dispatched to queue.');
            }
        }

        if ($sync) {
            $run = ['seconds' => round($started->diffInMilliseconds(now()) / 1000, 1), 'peak_memory_mb' => (int) round(memory_get_peak_usage(true) / 1048576)];
            // The hosted stack logs warnings and up, so only a run worth looking at reaches the file.
            $worthALook = $run['seconds'] > self::SLOW_RUN_SECONDS || $run['peak_memory_mb'] > self::HEAVY_RUN_MB;
            Log::log($worthALook ? 'warning' : 'info', 'Trello sync run finished', $run);
        }

        return self::SUCCESS;
    }
}

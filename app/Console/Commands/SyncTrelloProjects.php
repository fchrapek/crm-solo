<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncTrelloProjectsJob;
use App\Models\Integration;
use App\Services\TaskSources\TaskSourceRegistry;
use Illuminate\Console\Command;

final class SyncTrelloProjects extends Command
{
    protected $signature = 'trello:sync
                            {--account= : The account ID to sync (syncs all if not specified)}
                            {--force : Skip the confirmation prompt}
                            {--sync : Run synchronously instead of dispatching a job}';

    protected $description = 'Sync projects and tasks from connected Trello boards';

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

        foreach ($integrations as $integration) {
            $this->info("Processing account ID: {$integration->account_id}");

            if ($sync) {
                $stats = $taskSources->get('trello')->syncAll($integration);

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

        return self::SUCCESS;
    }
}

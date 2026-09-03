<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncClockifyTimeEntriesJob;
use App\Models\Integration;
use App\Services\Integrations\Clockify\ClockifyService;
use Illuminate\Console\Command;

final class SyncClockifyTimeEntries extends Command
{
    protected $signature = 'clockify:sync
                            {--account= : The account ID to sync (syncs all if not specified)}
                            {--since= : Only sync entries after this date (YYYY-MM-DD)}
                            {--force : Skip the confirmation prompt}
                            {--sync : Run synchronously instead of dispatching a job}';

    protected $description = 'Sync time entries from connected Clockify accounts';

    public function handle(): int
    {
        $accountId = $this->option('account');
        $since = $this->option('since');
        $force = $this->option('force');
        $sync = $this->option('sync');

        $query = Integration::where('provider', 'clockify')
            ->where('is_enabled', true);

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $integrations = $query->get()->filter(fn ($i) => $i->hasValidApiKey());

        if ($integrations->isEmpty()) {
            $this->error('No enabled Clockify integrations found.');

            return self::FAILURE;
        }

        if (! $force) {
            $this->info("Found {$integrations->count()} Clockify integration(s) to sync.");

            if (! $this->confirm('Do you want to continue?')) {
                $this->info('Sync cancelled.');

                return self::SUCCESS;
            }
        }

        foreach ($integrations as $integration) {
            $this->info("Processing account ID: {$integration->account_id}");

            if ($sync) {
                $service = new ClockifyService($integration);
                $stats = $service->syncTimeEntries($integration->account_id, $since);

                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Created', $stats['created']],
                        ['Updated', $stats['updated']],
                        ['Errors', $stats['errors']],
                    ]
                );
            } else {
                SyncClockifyTimeEntriesJob::dispatch($integration, $since);
                $this->info('  → Job dispatched to queue.');
            }
        }

        return self::SUCCESS;
    }
}

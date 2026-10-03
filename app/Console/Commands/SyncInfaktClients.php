<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Integration;
use App\Services\Integrations\InfaktService;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class SyncInfaktClients extends Command
{
    protected $signature = 'infakt:sync-clients
                            {--account= : The account ID to sync (syncs all if not specified)}
                            {--test : Test the API connection without syncing}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Sync clients from Infakt to local database (operator: every account with Infakt, or the one named by --account)';

    public function handle(): int
    {
        $accountId = $this->option('account');
        $testOnly = $this->option('test');
        $force = $this->option('force');

        $query = Integration::where('provider', 'infakt')
            ->where('is_enabled', true)
            ->whereNotNull('api_key');

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->error('No enabled Infakt integrations found.');

            return self::FAILURE;
        }

        if (! $testOnly && ! $force) {
            $this->warn('WARNING: This sync will update existing clients matched by NIP or external ID.');
            $this->warn('Fields edited in the CRM are kept, and clients deleted in the CRM stay deleted.');
            $this->newLine();

            if (! $this->confirm('Do you want to continue?')) {
                $this->info('Sync cancelled.');

                return self::SUCCESS;
            }

            $this->newLine();
        }

        foreach ($integrations as $integration) {
            $this->info("Processing account ID: {$integration->account_id}");

            $service = new InfaktService($integration);

            if ($testOnly) {
                $this->testConnection($service);

                continue;
            }

            $this->syncClients($service, $integration->account_id);
        }

        return self::SUCCESS;
    }

    private function testConnection(InfaktService $service): void
    {
        $this->info('Testing Infakt API connection...');

        if ($service->testConnection()) {
            $this->info('Connection successful!');
        } else {
            $this->error('Connection failed. Please check your API key.');
        }
    }

    private function syncClients(InfaktService $service, int $accountId): void
    {
        $this->info('Fetching clients from Infakt...');

        $stats = $service->syncClients($accountId);

        $this->newLine();
        $this->info('Sync completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
                ['Errors', $stats['errors']],
                ['Conflicts (not linked)', count($stats['conflicts'])],
            ]
        );

        foreach ($stats['conflicts'] as $conflict) {
            $this->warn($conflict);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktService;
use Illuminate\Console\Command;

final class SyncInfaktInvoices extends Command
{
    protected $signature = 'infakt:sync-invoices
                            {--account= : The account ID to sync (syncs all if not specified)}
                            {--since= : Only sync invoices issued on/after this YYYY-MM-DD date}';

    protected $description = 'Sync invoices from Infakt into the local invoices table for revenue analytics';

    public function handle(): int
    {
        $accountId = $this->option('account');
        $since = $this->option('since');

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

        foreach ($integrations as $integration) {
            $this->info("Processing account ID: {$integration->account_id}");

            try {
                $stats = (new InfaktService($integration))->syncInvoices($integration->account_id, $since);
            } catch (InfaktApiException $e) {
                $this->error("Infakt API error: {$e->userMessage()}");

                continue;
            }

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Created', $stats['created']],
                    ['Updated', $stats['updated']],
                    ['Errors', $stats['errors']],
                ]
            );
        }

        return self::SUCCESS;
    }
}

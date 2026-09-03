<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Integrations\InfaktService;
use Illuminate\Console\Command;

final class InfaktClientLookup extends Command
{
    protected $signature = 'infakt:client {ref : Infakt client numeric id or UUID} {--account=1}';

    protected $description = 'Fetch a single Infakt client (by numeric id or UUID) — prints its id/name/NIP.';

    public function handle(): int
    {
        $integration = Integration::where('provider', 'infakt')
            ->where('account_id', (int) $this->option('account'))
            ->where('is_enabled', true)
            ->whereNotNull('api_key')
            ->first();

        if ($integration === null) {
            $this->error('No enabled Infakt integration.');

            return self::FAILURE;
        }

        $result = (new InfaktService($integration))->getClient((string) $this->argument('ref'));
        $this->info("HTTP {$result['status']}");

        $body = $result['body'];
        if (is_array($body)) {
            $this->line('id: '.($body['id'] ?? 'n/a'));
            $this->line('name: '.($body['company_name'] ?? mb_trim(($body['first_name'] ?? '').' '.($body['last_name'] ?? ''))));
            $this->line('NIP: '.($body['nip'] ?? 'n/a'));
        } else {
            $this->line((string) $body);
        }

        return self::SUCCESS;
    }
}

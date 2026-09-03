<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Agent\ClientConfigData;
use Illuminate\Console\Command;

final class ClientConfig extends Command
{
    protected $signature = 'client:config {client : Client id} {--json : Output as JSON}';

    protected $description = "Show a client's month-close config (cohort, ssh, backup path, local repo, invoicing) — the per-client inputs the month-close-site skill needs.";

    public function handle(ClientConfigData $configData): int
    {
        $client = Client::find($this->argument('client'));

        if ($client === null) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $config = $configData->for($client);

        if ($this->option('json')) {
            $this->line(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($config as $key => $value) {
            $rendered = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : ($value ?? '—');
            $this->line(sprintf('%-32s %s', $key, $rendered));
        }

        return self::SUCCESS;
    }
}

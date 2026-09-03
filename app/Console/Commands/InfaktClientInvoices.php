<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Integration;
use App\Services\Integrations\InfaktService;
use Illuminate\Console\Command;

final class InfaktClientInvoices extends Command
{
    protected $signature = 'infakt:invoices {client : local CRM client id} {--account=1} {--limit=12} {--services : print each invoice line item}';

    protected $description = 'List a client\'s recent Infakt invoices (newest first) — reads the recurring maintenance amount before drafting.';

    public function handle(): int
    {
        $client = Client::find((int) $this->argument('client'));
        if ($client === null) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $infaktId = $client->external_ids['infakt'] ?? null;
        if ($infaktId === null) {
            $this->error("Client {$client->name} has no linked Infakt id.");

            return self::FAILURE;
        }

        $integration = Integration::where('provider', 'infakt')
            ->where('account_id', (int) $this->option('account'))
            ->where('is_enabled', true)
            ->whereNotNull('api_key')
            ->first();

        if ($integration === null) {
            $this->error('No enabled Infakt integration.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $invoices = (new InfaktService($integration))
            ->fetchClientInvoices((string) $infaktId, $limit)
            ->take($limit);

        $this->info("{$client->name} — Infakt id {$infaktId} — {$invoices->count()} invoice(s)");

        foreach ($invoices as $inv) {
            $net = number_format((int) ($inv['net_price'] ?? 0) / 100, 2, '.', '');
            $gross = number_format((int) ($inv['gross_price'] ?? 0) / 100, 2, '.', '');
            $this->line(sprintf(
                '%-14s inv=%s sale=%s  net=%s gross=%s %s  [%s]',
                $inv['number'] ?? '?',
                $inv['invoice_date'] ?? '?',
                $inv['sale_date'] ?? '?',
                $net,
                $gross,
                $inv['currency'] ?? 'PLN',
                $inv['status'] ?? '?'
            ));

            if ($this->option('services')) {
                foreach ((array) ($inv['services'] ?? []) as $svc) {
                    $svcNet = number_format((int) ($svc['net_price'] ?? 0) / 100, 2, '.', '');
                    $this->line(sprintf(
                        '    · %-6s  %s',
                        $svcNet,
                        $svc['name'] ?? '(no name)'
                    ));
                }
            }
        }

        return self::SUCCESS;
    }
}

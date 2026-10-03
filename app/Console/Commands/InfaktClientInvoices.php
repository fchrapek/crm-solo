<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Integration;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Integrations\InfaktService;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::ACTING)]
final class InfaktClientInvoices extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'infakt:invoices {client : local CRM client id} {--account= : Account ID; must be the acting account} {--limit=12} {--services : print each invoice line item}';

    protected $description = 'List a client\'s recent Infakt invoices (newest first) — reads the recurring maintenance amount before drafting.';

    public function handle(): int
    {
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        try {
            $client = app(CrmEntityResolver::class)->client((string) $this->argument('client'), $accountId);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $infaktId = $client->external_ids['infakt'] ?? null;
        if ($infaktId === null) {
            $this->error("Client {$client->name} has no linked Infakt id.");

            return self::FAILURE;
        }

        $integration = Integration::where('provider', 'infakt')
            ->where('account_id', $accountId)
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

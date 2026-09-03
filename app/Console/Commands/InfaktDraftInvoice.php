<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Integration;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class InfaktDraftInvoice extends Command
{
    protected $signature = 'infakt:draft-invoice
                            {client : Client id}
                            {--period= : Billed month YYYY-MM (default: previous month)}
                            {--dry-run : Print the payload without sending anything to Infakt}
                            {--status= : Poll an existing async task reference and print its status}';

    protected $description = 'Create a DRAFT maintenance invoice in Infakt for a maintenance client (retainer-derived, draft only — never issued or sent).';

    public function handle(): int
    {
        $client = Client::find($this->argument('client'));

        if ($client === null) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $period = $this->option('period')
            ? Carbon::createFromFormat('Y-m', $this->option('period'))->startOfMonth()
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();

        $integration = Integration::where('provider', 'infakt')
            ->where('account_id', $client->account_id)
            ->where('is_enabled', true)
            ->whereNotNull('api_key')
            ->first();

        if ($integration === null) {
            $this->error('No enabled Infakt integration for this account.');

            return self::FAILURE;
        }

        $service = new InfaktService($integration);

        if ($this->option('status')) {
            $result = $service->checkInvoiceTask((string) $this->option('status'));
            $this->info("Task status (HTTP {$result['status']}):");
            $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        try {
            if ($this->option('dry-run')) {
                $payloads = $service->buildMaintenanceInvoicePayloads($client, $period);
                $this->info("DRY RUN — {$this->pluralInvoices(count($payloads))} that WOULD be sent for {$client->name} ({$period->format('Y-m')}), nothing sent:");
                $this->line(json_encode($payloads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $results = $service->createDraftMaintenanceInvoices($client, $period);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (InfaktApiException $e) {
            $this->error("Infakt API error: {$e->userMessage()}");

            return self::FAILURE;
        }

        $this->info("Draft {$this->pluralInvoices(count($results))} accepted for {$client->name} ({$period->format('Y-m')}).");

        foreach ($results as $i => $result) {
            $invoice = $result['payload']['invoice'];
            $this->line('');
            $this->line('Invoice #'.($i + 1).' — task reference: '.($result['task_reference'] ?? 'n/a'));
            $this->line('  Sale date: '.$invoice['sale_date'].'  |  Issue date: '.$invoice['invoice_date']);
            foreach ($invoice['services'] as $service_line) {
                $this->line('  · '.number_format($service_line['unit_net_price'] / 100, 2).' net  '.str_replace("\n", ' / ', (string) $service_line['name']));
            }

            if ($result['status'] !== null) {
                $this->line('  Status: '.json_encode($result['status'], JSON_UNESCAPED_UNICODE));
            } else {
                $this->comment('  Still processing async — verify the draft appears in Infakt shortly.');
            }
        }

        return self::SUCCESS;
    }

    private function pluralInvoices(int $n): string
    {
        return $n === 1 ? '1 invoice' : "{$n} invoices";
    }
}

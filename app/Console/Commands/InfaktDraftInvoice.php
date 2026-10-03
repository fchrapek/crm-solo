<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Integration;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktDraftRun;
use App\Services\Integrations\InfaktService;
use App\Support\LocalCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

#[AccountScope(AccountScope::ACTING)]
final class InfaktDraftInvoice extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'infakt:draft-invoice
                            {client : Client id}
                            {--period= : Billed month YYYY-MM (default: previous month)}
                            {--dry-run : Print the payload without sending anything to Infakt}
                            {--status= : Poll an existing async task reference and print its status}
                            {--resend-unconfirmed : Resend groups whose earlier request got no answer (check Infakt first)}';

    protected $description = 'Create a DRAFT maintenance invoice in Infakt for a maintenance client (retainer-derived, draft only — never issued or sent).';

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

        try {
            $period = Carbon::instance(LocalCalendar::monthFrom(
                (string) ($this->option('period') ?: LocalCalendar::previousMonth()),
                (string) config('app.timezone'),
            ));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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

            $results = $service->createDraftMaintenanceInvoices($client, $period, (bool) $this->option('resend-unconfirmed'));
        } catch (InfaktApiException $e) {
            $this->error("Infakt API error: {$e->userMessage()}. Groups drafted before it stay recorded; run again to resume.");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$client->name} ({$period->format('Y-m')}):");

        $labels = [
            InfaktDraftRun::CREATED => 'draft created',
            InfaktDraftRun::ALREADY_DRAFTED => 'already drafted earlier, skipped',
            InfaktDraftRun::PROCESSING => 'sent, Infakt still processing',
            InfaktDraftRun::UNCONFIRMED => 'not sent: earlier request unconfirmed',
            InfaktDraftRun::BUSY => 'not sent: another run holds it',
        ];

        foreach ($results as $result) {
            $invoice = $result['payload']['invoice'];
            $this->line('');
            $this->line("Invoice group {$result['group']}: {$labels[$result['outcome']]}".($result['task_reference'] ? " (task {$result['task_reference']})" : ''));
            $this->line('  Sale date: '.$invoice['sale_date'].'  |  Issue date: '.$invoice['invoice_date']);
            foreach ($invoice['services'] as $service_line) {
                $this->line('  · '.number_format($service_line['unit_net_price'] / 100, 2).' net  '.str_replace("\n", ' / ', (string) $service_line['name']));
            }
            if ($result['message'] !== null) {
                $this->comment('  '.$result['message']);
            }
        }

        $settled = [InfaktDraftRun::CREATED, InfaktDraftRun::ALREADY_DRAFTED, InfaktDraftRun::PROCESSING];

        return collect($results)->every(fn (array $r): bool => in_array($r['outcome'], $settled, true)) ? self::SUCCESS : self::FAILURE;
    }

    private function pluralInvoices(int $n): string
    {
        return $n === 1 ? '1 invoice' : "{$n} invoices";
    }
}

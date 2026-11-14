<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktDrafting;
use App\Services\Integrations\InfaktDraftRun;
use Illuminate\Console\Command;
use RuntimeException;

#[AccountScope(AccountScope::ACTING)]
final class InfaktDraftInvoice extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'infakt:draft-invoice
                            {client : Client id}
                            {--period= : Billed month YYYY-MM (default: previous month)}
                            {--like= : Copy real invoices instead of the retainer: "last" (each group from its previous invoice), or an Infakt invoice number to pick}
                            {--project= : Only the invoice group of this project (id or name fragment)}
                            {--dry-run : Print the payload without sending anything to Infakt}
                            {--status= : Poll an existing async task reference and print its status}
                            {--resend-unconfirmed : Resend groups whose earlier request got no answer (check Infakt first)}';

    protected $description = 'Create DRAFT maintenance invoices in Infakt, one per invoice group (retainer or copies; draft only, never issued or sent).';

    public function handle(): int
    {
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        try {
            $client = app(CrmEntityResolver::class)->client((string) $this->argument('client'), $accountId);
            $project = $this->option('project') ? app(CrmEntityResolver::class)->project(mb_trim((string) $this->option('project')), $accountId, $client->id) : null;
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $drafting = app(InfaktDrafting::class);
        $period = $this->option('period') ?: null;
        $like = $this->option('like') !== null ? mb_trim((string) $this->option('like')) : null;

        if ($this->option('status')) {
            try {
                $result = $drafting->service($client)->checkInvoiceTask((string) $this->option('status'));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("Task status (HTTP {$result['status']}):");
            $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        try {
            if ($this->option('dry-run')) {
                $preview = $drafting->preview($client, $period, $like, $project);
                $this->info("DRY RUN: {$this->pluralInvoices(count($preview['results']))} for {$client->name} ({$preview['period']}), nothing sent:");
                $this->line(json_encode($preview['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $run = $drafting->draft($client, $period, $like, (bool) $this->option('resend-unconfirmed'), $project);
            $results = $run['results'];
        } catch (InfaktApiException $e) {
            $this->error("Infakt API error: {$e->userMessage()}. Groups drafted before it stay recorded; run again to resume.");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$client->name} ({$run['period']}):");

        $labels = [
            InfaktDraftRun::CREATED => 'draft created',
            InfaktDraftRun::ALREADY_DRAFTED => 'already drafted earlier, skipped',
            InfaktDraftRun::PROCESSING => 'sent, Infakt still processing',
            InfaktDraftRun::UNCONFIRMED => 'not sent: earlier request unconfirmed',
            InfaktDraftRun::BUSY => 'not sent: another run holds it',
            InfaktDrafting::NEEDS_SOURCE => 'not sent: pick the invoice to copy',
            InfaktDrafting::BLOCKED => 'not sent: the month holds an older copy not tied to a project',
        ];

        foreach ($results as $result) {
            $this->line('');
            $from = $result['source']['invoice_number'] !== null ? ", copy of {$result['source']['invoice_number']}" : '';
            $this->line("Invoice group {$result['group']}: ".($labels[$result['outcome']] ?? $result['outcome'])."{$from}".($result['task_reference'] ? " (task {$result['task_reference']})" : ''));
            $invoice = $result['payload']['invoice'] ?? null;
            if ($invoice !== null) {
                $this->line('  Sale date: '.$invoice['sale_date'].'  |  Issue date: '.$invoice['invoice_date']);
                foreach ($invoice['services'] as $service_line) {
                    $this->line('  · '.number_format($service_line['unit_net_price'] / 100, 2).' net  '.str_replace("\n", ' / ', (string) $service_line['name']));
                }
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

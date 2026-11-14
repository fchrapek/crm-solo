<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktDrafting;
use App\Services\Integrations\InfaktDraftRun;
use Illuminate\Console\Command;
use RuntimeException;

/** Agent verb: Infakt DRAFT invoices for a client's month, one per invoice group, from the retainer or as copies. Never issued or sent. */
#[AccountScope(AccountScope::ACTING)]
final class CrmInvoiceDraft extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:invoice-draft
                            {client : Client id or name fragment}
                            {--period= : The billed month YYYY-MM (default: previous month)}
                            {--like= : Copy real invoices instead of the retainer: "last" (each group from its previous invoice), or an Infakt invoice number to pick}
                            {--project= : Only the invoice group of this project (id or name fragment); needed with an invoice number when there are several groups}
                            {--dry-run : Show the draft without sending anything to Infakt}
                            {--json : Machine-readable output}';

    protected $description = 'Create Infakt DRAFT invoices per invoice group (retainer, or a copy of each group\'s previous invoice); never issued or sent';

    public function handle(InfaktDrafting $drafting, CrmEntityResolver $resolver): int
    {
        $like = $this->option('like') !== null ? mb_trim((string) $this->option('like')) : null;
        $period = $this->option('period') !== null ? (string) $this->option('period') : null;

        try {
            $accountId = $this->actingIdentity()->account->id;
            $client = $resolver->client((string) $this->argument('client'), $accountId);
            $project = $this->option('project') !== null ? $resolver->project(mb_trim((string) $this->option('project')), $accountId, $client->id) : null;
            $result = $this->option('dry-run') ? $drafting->preview($client, $period, $like, $project) : $drafting->draft($client, $period, $like, project: $project);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (InfaktApiException $e) {
            return $this->refusal(new AgentRefusal(AgentRefusal::REFUSED, "Infakt said no: {$e->userMessage()}. Groups drafted before it stay recorded."));
        } catch (RuntimeException $e) {
            return $this->refusal(new AgentRefusal(AgentRefusal::REFUSED, $e->getMessage()));
        }

        $settled = collect($result['results'])->every(
            fn (array $r): bool => in_array($r['outcome'], [InfaktDrafting::PREVIEW, InfaktDraftRun::CREATED, InfaktDraftRun::ALREADY_DRAFTED, InfaktDraftRun::PROCESSING], true),
        );

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson(['client_id' => $client->id, 'dry_run' => (bool) $this->option('dry-run'), ...$result]));

            return $settled ? self::SUCCESS : self::FAILURE;
        }

        $this->raw(($this->option('dry-run') ? 'DRY RUN, nothing sent. ' : '').$this->literal($client->name)." · {$result['period']} · ".($result['mode'] === 'copy' ? 'copies' : 'from the retainer'));
        foreach ($result['results'] as $row) {
            $projects = collect($row['project'])->pluck('name')->filter()->implode(', ');
            $from = $row['source']['invoice_number'] !== null ? ' · copy of '.$this->literal($row['source']['invoice_number']) : '';
            $this->raw("Group {$row['group']}".($projects !== '' ? ' ('.$this->literal($projects).')' : '')." · {$row['outcome']}{$from}".($row['message'] !== null ? ' · '.$row['message'] : ''));
            $invoice = $row['payload']['invoice'] ?? null;
            if ($invoice !== null) {
                $this->raw("  sale {$invoice['sale_date']}, issue {$invoice['invoice_date']}".(isset($invoice['payment_date']) ? ", due {$invoice['payment_date']}" : ''));
                $this->fenced(array_map(fn (array $line): string => number_format($line['unit_net_price'] / 100, 2, ',', ' ').' net x '.$line['quantity'].'  '.str_replace("\n", ' / ', $line['name']), $invoice['services']));
            }
        }

        return $settled ? self::SUCCESS : self::FAILURE;
    }
}

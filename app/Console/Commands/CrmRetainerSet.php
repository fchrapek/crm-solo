<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\RetainerEditor;
use Illuminate\Console\Command;

/** Agent verb: add an abonament position, or change the given fields of one; the abonament dialog's rules apply. */
#[AccountScope(AccountScope::ACTING)]
final class CrmRetainerSet extends Command
{
    use AgentConsoleOutput;

    /** Option => position field. */
    private const array OPTIONS = [
        'hours' => 'monthly_hours', 'fee' => 'monthly_fee', 'overage' => 'overage_hourly_rate', 'rollover' => 'rollover_cap_hours',
        'label' => 'label', 'description' => 'description', 'project' => 'project_id', 'group' => 'invoice_group',
        'vat' => 'vat_symbol', 'currency' => 'currency', 'from' => 'effective_from', 'notes' => 'notes',
    ];

    protected $signature = 'crm:retainer-set
                            {client : Client id or name fragment}
                            {--id= : Position id from crm brief; omit to add a new position}
                            {--hours= : Monthly hours (required for a new position)}
                            {--fee= : Monthly net fee}
                            {--overage= : Hourly rate over the pool}
                            {--rollover= : Most hours carried to the next month}
                            {--label= : Name, as on the invoice line}
                            {--description= : Longer description}
                            {--project= : Project id the position covers}
                            {--group= : Invoice group (1 and up); positions in one group share an invoice}
                            {--vat= : VAT symbol, e.g. 23}
                            {--currency= : Three-letter currency}
                            {--from= : First day it applies, YYYY-MM-DD (new position default: today)}
                            {--notes= : Internal notes}
                            {--json : Machine-readable output}';

    protected $description = 'Add a retainer position or change some of its fields ("none" clears a field)';

    public function handle(RetainerEditor $editor): int
    {
        $fields = [];
        foreach (self::OPTIONS as $option => $field) {
            $value = $this->option($option);
            if ($value !== null) {
                $fields[$field] = $value === 'none' ? null : (string) $value;
            }
        }
        $id = $this->option('id');
        if ($id !== null && ! ctype_digit((string) $id)) {
            return $this->refusal(AgentRefusal::invalid('--id is a position id; crm brief lists them.'));
        }

        try {
            $result = $editor->set($this->actingIdentity()->account, (string) $this->argument('client'), $id !== null ? (int) $id : null, $fields);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        return $this->retainerResult($result);
    }

    /** @param  array{client: array{id: int, name: string}, retainer: array<string, mixed>, message: string}  $result */
    private function retainerResult(array $result): int
    {
        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $r = $result['retainer'];
        $this->raw(($result['message'] !== '' ? $result['message'] : 'Nothing changed.').' '.$this->literal($result['client']['name'])." · position #{$r['id']}");
        $this->raw($this->literal((string) ($r['label'] ?? '-'))." · {$r['monthly_hours']} h".($r['monthly_fee'] !== null ? " · {$r['monthly_fee']} {$r['currency']}" : '')." · from {$r['effective_from']}".($r['effective_to'] !== null ? " to {$r['effective_to']}" : ''));

        return self::SUCCESS;
    }
}

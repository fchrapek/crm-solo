<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\BilledTimeEditor;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/** Agent verb: a client's month in both values, real and billed, with the balance the billed figure leads to. */
#[AccountScope(AccountScope::ACTING)]
final class CrmBilled extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:billed
                            {client : Client id or name fragment}
                            {--month= : The month YYYY-MM (default: the oldest month still open)}
                            {--json : Machine-readable output}';

    protected $description = 'Show real and billed time of a client month';

    public function handle(BilledTimeEditor $editor): int
    {
        try {
            $figures = $editor->read($this->actingIdentity()->account, (string) $this->argument('client'), $this->option('month') !== null ? (string) $this->option('month') : null);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($figures));

            return self::SUCCESS;
        }

        $this->printFigures($figures);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $f */
    private function printFigures(array $f): void
    {
        $this->raw($this->literal((string) $f['client'])." · {$f['period']}");
        $this->raw("  real {$f['real']} h, billed ".($f['billed'] ?? $f['real']).' h (each task its stated figure, else its real time)');
        if ($f['tasks'] !== []) {
            $this->fenced(array_map(
                fn (array $t): string => "  #{$t['task_id']} ".$this->literal((string) $t['task']).": real {$t['real']} h, billed {$t['billed']} h".($t['stated'] !== null ? ' (stated)' : '').($t['note'] !== null ? ', note: '.$this->literal((string) $t['note']) : ''),
                $f['tasks'],
            ));
        }
        if ($f['note'] !== null) {
            $this->raw('  note: '.$this->literal((string) $f['note']));
        }
        if ($f['has_retainer']) {
            $this->raw("  opening {$f['opening']} h, available {$f['available']} h, closing {$f['closing']} h");
        }
        $this->raw($f['refusal'] !== null ? '  cannot change: '.$this->literal((string) $f['refusal']) : '  can change');
    }
}

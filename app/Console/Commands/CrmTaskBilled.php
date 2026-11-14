<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\BilledTimeEditor;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/** Agent verb: a task's real and billed time for a month; with --hours or --clear it sets or drops the billed figure. Real time is never touched. */
#[AccountScope(AccountScope::ACTING)]
final class CrmTaskBilled extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:task-billed
                            {task : Task id or name fragment}
                            {--month= : The month YYYY-MM (default: the month the task was last worked in)}
                            {--hours= : Billed hours for the task, 0 or more}
                            {--clear : Drop the figure; the task bills its real time again}
                            {--note= : Internal note in Polish, never shown to the client}
                            {--json : Machine-readable output}';

    protected $description = 'Show a task\'s real and billed time, or set its billed hours';

    public function handle(BilledTimeEditor $editor): int
    {
        $hours = $this->option('hours');
        if ($hours !== null && ! is_numeric($hours)) {
            return $this->refusal(AgentRefusal::invalid('--hours takes a number.'));
        }
        $account = $this->actingIdentity()->account;
        $task = (string) $this->argument('task');
        $month = $this->option('month') !== null ? (string) $this->option('month') : null;
        $writes = $hours !== null || (bool) $this->option('clear');

        try {
            $result = $writes
                ? $editor->taskSet($account, $task, $month, $hours !== null ? (float) $hours : null, (bool) $this->option('clear'), $this->option('note') !== null ? (string) $this->option('note') : null)
                : ['task' => $editor->taskRead($account, $task, $month)];
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($writes ? $result : $result['task']));

            return self::SUCCESS;
        }

        if ($writes) {
            $this->raw($this->literal((string) $result['message']));
        }
        $this->printTask($result['task']);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $t */
    private function printTask(array $t): void
    {
        $this->raw("Task #{$t['task_id']} · ".$this->literal((string) $t['client'])." · {$t['period']}".($t['reportable'] ? '' : ' · NOT reportable'));
        $this->fenced(['Task: '.$this->literal((string) $t['task'])]);
        $this->raw("  real {$t['real']} h, billed ".($t['billed'] ?? 0).' h'.($t['stated'] !== null ? ' (stated)' : ' (from real time)'));
        $m = $t['month'];
        $this->raw("  month: real {$m['real']} h, billed {$m['billed']} h".($m['closing'] !== null ? ", closing {$m['closing']} h" : ''));
        if ($t['note'] !== null) {
            $this->raw('  note: '.$this->literal((string) $t['note']));
        }
        foreach ($t['other_months'] as $other) {
            $this->raw("  also billed {$other['stated']} h in {$other['period']}");
        }
        $this->raw($t['refusal'] !== null ? '  cannot change: '.$this->literal((string) $t['refusal']) : '  can change');
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\MonthCloseReader;
use Illuminate\Console\Command;

/** Agent verb: every client in a period's month close, with its checklist, report and financial close. */
#[AccountScope(AccountScope::ACTING)]
final class CrmMonthClose extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:month-close
                            {--period= : Billed month YYYY-MM (default: previous month)}
                            {--json : Machine-readable output}';

    protected $description = 'The month close worklist: each client\'s checklist, report and settlement for a period';

    public function handle(MonthCloseReader $reader): int
    {
        try {
            $overview = $reader->overview($this->actingIdentity()->account, $this->option('period') !== null ? (string) $this->option('period') : null);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($overview));

            return self::SUCCESS;
        }

        $this->raw("Month close {$overview['period']}: ".count($overview['clients']).' clients.');
        foreach ($overview['clients'] as $row) {
            $checklist = $row['checklist'] !== null ? "checklist {$row['checklist']['status']} {$row['checklist']['resolved']}/{$row['checklist']['total']}" : 'no checklist';
            $report = $row['report']['report_id'] !== null ? "report #{$row['report']['report_id']} {$row['report']['status']}" : 'no report';
            $settlement = $row['settlement'] !== null ? "settlement {$row['settlement']['state']}" : 'nothing to settle';
            $this->raw("  #{$row['client_id']} ".$this->literal($row['client'])." · {$checklist} · {$row['report']['mode']}: {$report} · {$settlement}");
        }

        return self::SUCCESS;
    }
}

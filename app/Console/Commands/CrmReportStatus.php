<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\ReportEditor;
use Illuminate\Console\Command;

/** Agent verb: finalize a report, mark it sent, or reopen it as a draft, as the buttons on the report page. */
#[AccountScope(AccountScope::ACTING)]
final class CrmReportStatus extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:report-status
                            {report : Report id}
                            {action : finalize, sent or reopen}
                            {--json : Machine-readable output}';

    protected $description = 'Finalize a report, mark it sent, or reopen it';

    public function handle(ReportEditor $editor): int
    {
        return $this->report(fn (): array => $editor->status(
            $this->actingIdentity()->account,
            (string) $this->argument('report'),
            (string) $this->argument('action'),
        ));
    }

    /** @param  callable(): array<string, mixed>  $write */
    private function report(callable $write): int
    {
        try {
            $result = $write();
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $report = $result['report'];
        $this->raw(($result['message'] !== '' ? $result['message'] : 'Nothing changed.')." Report #{$report['id']} · ".$this->literal($report['client'])." · {$report['period']} · {$report['status']}");
        $this->raw('Edit: '.$this->literal($report['edit_url']));

        return self::SUCCESS;
    }
}

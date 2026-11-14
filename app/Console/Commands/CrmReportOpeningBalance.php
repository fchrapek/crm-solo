<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\ReportEditor;
use Illuminate\Console\Command;

/** Agent verb: set the hours carried into a draft report's month; the body's billing summary follows. */
#[AccountScope(AccountScope::ACTING)]
final class CrmReportOpeningBalance extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:report-opening-balance
                            {report : Report id}
                            {hours : Hours carried in, negative for an overrun; "none" clears it}
                            {--json : Machine-readable output}';

    protected $description = 'Set the opening balance of a draft report';

    public function handle(ReportEditor $editor): int
    {
        $hours = (string) $this->argument('hours');
        if ($hours !== 'none' && (! is_numeric($hours) || abs((float) $hours) > 9999)) {
            return $this->refusal(AgentRefusal::invalid('The hours are a number between -9999 and 9999, or none.'));
        }

        return $this->report(fn (): array => $editor->openingBalance(
            $this->actingIdentity()->account,
            (string) $this->argument('report'),
            $hours === 'none' ? null : (float) $hours,
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

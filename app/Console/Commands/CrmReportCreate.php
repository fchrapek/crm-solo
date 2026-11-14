<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\ReportEditor;
use Illuminate\Console\Command;

/** Agent verb: a draft report for one client's month, as Generate on the client's Rozliczenia tab. A second report for the month is refused. */
#[AccountScope(AccountScope::ACTING)]
final class CrmReportCreate extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:report-create
                            {client : Client id or name fragment}
                            {--period= : The month YYYY-MM (default: previous month)}
                            {--hours= : Billed hours; they must be the sum of the task figures (set those with task-billed)}
                            {--composer= : Composer key (default: the account default)}
                            {--locale=pl : Report language, pl or en}
                            {--json : Machine-readable output}';

    protected $description = 'Generate a draft report for a client\'s month';

    public function handle(ReportEditor $editor): int
    {
        $hours = $this->option('hours');
        if ($hours !== null && ! is_numeric($hours)) {
            return $this->refusal(AgentRefusal::invalid('--hours takes a number.'));
        }

        return $this->report(fn (): array => $editor->create(
            $this->actingIdentity()->account,
            (string) $this->argument('client'),
            $this->option('period') !== null ? (string) $this->option('period') : null,
            $this->option('composer') ?: null,
            $hours !== null ? (float) $hours : null,
            (string) $this->option('locale'),
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

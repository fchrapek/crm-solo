<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\ReportEditor;
use Illuminate\Console\Command;

/** Agent verb: replace a draft report's body. The version from `crm report` guards against overwriting a newer edit. */
#[AccountScope(AccountScope::ACTING)]
final class CrmReportUpdate extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:report-update
                            {report : Report id}
                            {--body= : The whole new body, Markdown}
                            {--version= : The version `crm report --json` printed}
                            {--json : Machine-readable output}';

    protected $description = 'Replace the body of a draft report';

    public function handle(ReportEditor $editor): int
    {
        if (! is_string($this->option('body')) || mb_trim($this->option('body')) === '' || ! is_string($this->option('version'))) {
            return $this->refusal(AgentRefusal::invalid('Pass the whole new --body and the --version `crm report --json` printed.'));
        }

        return $this->report(fn (): array => $editor->updateBody(
            $this->actingIdentity()->account,
            (string) $this->argument('report'),
            $this->option('body'),
            $this->option('version'),
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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\ReportEditor;
use Illuminate\Console\Command;

/** Agent verb: recompose a draft report's body and refresh its figures, as Regenerate on the report page. */
#[AccountScope(AccountScope::ACTING)]
final class CrmReportRegenerate extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:report-regenerate
                            {report : Report id}
                            {--composer= : Composer key (default: the report\'s own)}
                            {--recalculate : Carry the opening balance in again from the settled month before}
                            {--locale=pl : Report language, pl or en}
                            {--json : Machine-readable output}';

    protected $description = 'Regenerate a draft report';

    public function handle(ReportEditor $editor): int
    {
        return $this->report(fn (): array => $editor->regenerate(
            $this->actingIdentity()->account,
            (string) $this->argument('report'),
            $this->option('composer') ?: null,
            (bool) $this->option('recalculate'),
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

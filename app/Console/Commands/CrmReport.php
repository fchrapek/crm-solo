<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\MonthCloseReader;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/** Agent verb: one report with the hours the Rozliczenia tab shows for its period. The body is fenced. */
#[AccountScope(AccountScope::ACTING)]
final class CrmReport extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:report
                            {report : Report id, or a client name fragment (a client id with --period)}
                            {--period= : The client\'s month YYYY-MM (default: previous month)}
                            {--json : Machine-readable output}';

    protected $description = 'One client report: status, hours bank figures, body, composer, revisions, edit URL';

    public function handle(MonthCloseReader $reader, CrmEntityResolver $resolver): int
    {
        $needle = (string) $this->argument('report');
        $period = $this->option('period') !== null ? (string) $this->option('period') : null;
        $account = $this->actingIdentity()->account;

        try {
            $report = ctype_digit($needle) && $period === null
                ? $reader->reportById($account, $needle)
                : $reader->reportFor($resolver->client($needle, $account->id), $period);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($report));

            return self::SUCCESS;
        }

        $hours = $report['hours'];
        $this->raw("# Report #{$report['id']} · ".$this->literal($report['client'])." · {$report['period_start']} to {$report['period_end']} · {$report['status']}".($report['governs'] ? '' : ' (not the one the hours bank reads)'));
        $this->raw('Hours: '.($hours['contracted'] ?? '-')." contracted, {$hours['opening']} carried, {$hours['available']} available, {$hours['used']} used, {$hours['forfeited']} forfeited, {$hours['closing']} closing");
        $this->raw('Composer: '.$this->literal($report['composer_key'])." · revisions: {$report['revision_count']}".($report['needs_recalculation'] ? ' · needs recalculation' : ''));
        $this->raw('Edit: '.$this->literal($report['edit_url']));
        $this->fenced(explode("\n", $this->literal($report['body_markdown'], multiline: true)));

        return self::SUCCESS;
    }
}

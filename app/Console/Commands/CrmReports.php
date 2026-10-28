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

/** Agent verb: a client's reports, newest first, without their bodies. */
#[AccountScope(AccountScope::ACTING)]
final class CrmReports extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:reports
                            {client : Client id or name fragment}
                            {--json : Machine-readable output}';

    protected $description = 'A client\'s reports, newest first, without bodies';

    public function handle(MonthCloseReader $reader, CrmEntityResolver $resolver): int
    {
        try {
            $list = $reader->reports($resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id));
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($list));

            return self::SUCCESS;
        }

        $this->raw($this->literal($list['client']).": {$list['total']} reports.");
        foreach ($list['reports'] as $report) {
            $this->raw("  #{$report['id']} {$report['period_start']} to {$report['period_end']} · {$report['status']}".($report['governs'] ? '' : ' · not read by the hours bank'));
        }

        return self::SUCCESS;
    }
}

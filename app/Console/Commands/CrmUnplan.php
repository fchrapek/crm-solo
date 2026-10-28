<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\DayPlan;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/** Agent verb: take a task off today's or tomorrow's plan. */
#[AccountScope(AccountScope::ACTING)]
final class CrmUnplan extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:unplan {task : Task id, or a fragment of a picked task\'s name}
        {--day= : today (default), tomorrow or YYYY-MM-DD}
        {--json : Machine-readable output}';

    protected $description = "Take a task off today's or tomorrow's plan";

    public function handle(DayPlan $plans): int
    {
        $account = $this->actingIdentity()->account->id;

        try {
            $result = $plans->unplan($account, (string) $this->argument('task'), $plans->day($this->option('day')));
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw("Took task #{$result['task_id']} off {$result['plan']['date']}.");
        $this->fenced($this->planLines($result['plan']));

        return self::SUCCESS;
    }
}

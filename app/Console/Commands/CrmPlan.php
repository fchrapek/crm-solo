<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsAgentLists;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\DayPlan;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/**
 * Agent verb: pick a task for a day from today to the end of next week, through the day screen's
 * rules. Without a task it prints the day's plan, and is then a read.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmPlan extends Command
{
    use PrintsAgentLists;

    protected $signature = 'crm:plan {task? : Open task id or name fragment; omit to print the plan}
        {--day= : today (default), tomorrow or YYYY-MM-DD up to the end of next week}
        {--json : Machine-readable output}';

    protected $description = "Plan a task for a day up to the end of next week, or print the day's plan";

    public function handle(DayPlan $plans): int
    {
        $account = $this->actingIdentity()->account->id;
        $task = $this->argument('task');

        try {
            $date = $plans->day($this->option('day'));
            $result = $task === null
                ? ['plan' => $plans->read($account, $date)]
                : $plans->plan($account, (string) $task, $date);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        if ($task !== null) {
            $this->raw("Planned task #{$result['task_id']} for {$result['plan']['date']}.");
        }
        $this->fenced($this->planLines($result['plan']));

        return self::SUCCESS;
    }
}

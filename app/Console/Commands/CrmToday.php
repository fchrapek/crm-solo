<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Agent\TodayDigest;
use Illuminate\Console\Command;

/**
 * The cross-client morning glance for agents: what needs attention today.
 * Mirrors the dashboard's attention rule (priority high, or AI-high with no
 * human override) plus overdue tasks, every open month-close run (oldest
 * period first), hot leads still in play, and any running time entry.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmToday extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:today {--json : Machine-readable output}';

    protected $description = 'Cross-client attention list: urgent/overdue tasks, month-close, hot leads, running timers';

    public function handle(TodayDigest $digest): int
    {
        $data = $digest->build($this->actingIdentity()->account->id);
        $attention = $data['attention_tasks'];
        $monthClose = $data['month_close_open'];
        $hotLeads = $data['hot_leads'];
        $running = $data['running_time_entries'];

        if ($this->option('json')) {
            $this->raw($this->encodeJson($data));

            return self::SUCCESS;
        }

        // Task titles, lead names and client names come from cards, forms and imports: all of it is fenced.
        $lines = ['Attention tasks:'];
        foreach ($attention as $task) {
            $flag = $task['overdue'] ? ' (OVERDUE)' : '';
            $lines[] = "  #{$task['id']} [".$this->literal($task['client']).'] '.$this->literal($task['name']).($task['due'] ? " due {$task['due']}{$flag}" : '');
        }
        if ($attention->isEmpty()) {
            $lines[] = '  none';
        }

        $lines[] = 'Month close open:';
        foreach ($monthClose as $run) {
            $lines[] = "  {$run['period']} ".$this->literal($run['client'])." ({$run['type']})";
        }
        if ($monthClose->isEmpty()) {
            $lines[] = '  none';
        }

        $lines[] = 'Hot leads in play:';
        foreach ($hotLeads as $lead) {
            $lines[] = "  #{$lead['id']} ".$this->literal($lead['name'])." [{$lead['brand']}] at {$lead['stage']} -> {$lead['routing']}";
        }
        if ($hotLeads->isEmpty()) {
            $lines[] = '  none';
        }

        $lines[] = 'Running time entries:';
        foreach ($running as $entry) {
            $lines[] = "  #{$entry['id']} ".$this->literal($entry['client']).' - '.$this->literal($entry['task'] ?? 'no task')." since {$entry['started']}";
        }
        if ($running->isEmpty()) {
            $lines[] = '  none';
        }

        $this->fenced($lines);

        return self::SUCCESS;
    }
}

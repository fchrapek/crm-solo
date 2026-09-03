<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\TodayDigest;
use Illuminate\Console\Command;

/**
 * The cross-client morning glance for agents: what needs attention today.
 * Mirrors the dashboard's attention rule (priority high, or AI-high with no
 * human override) plus overdue tasks, open month-close runs for the current
 * period, hot leads still in play, and any running time entry.
 */
final class CrmToday extends Command
{
    protected $signature = 'crm:today {--json : Machine-readable output}';

    protected $description = 'Cross-client attention list: urgent/overdue tasks, month-close, hot leads, running timers';

    public function handle(TodayDigest $digest): int
    {
        $data = $digest->build();
        $attention = $data['attention_tasks'];
        $monthClose = $data['month_close_open'];
        $hotLeads = $data['hot_leads'];
        $running = $data['running_time_entries'];

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Attention tasks:');
        foreach ($attention as $task) {
            $flag = $task['overdue'] ? ' (OVERDUE)' : '';
            $this->line("  #{$task['id']} [{$task['client']}] {$task['name']}".($task['due'] ? " due {$task['due']}{$flag}" : ''));
        }
        if ($attention->isEmpty()) {
            $this->line('  none');
        }

        $this->info('Month close open ('.now()->format('Y-m').'):');
        foreach ($monthClose as $run) {
            $this->line("  {$run['client']} ({$run['type']})");
        }
        if ($monthClose->isEmpty()) {
            $this->line('  none');
        }

        $this->info('Hot leads in play:');
        foreach ($hotLeads as $lead) {
            $this->line("  #{$lead['id']} {$lead['name']} [{$lead['brand']}] at {$lead['stage']} → {$lead['routing']}");
        }
        if ($hotLeads->isEmpty()) {
            $this->line('  none');
        }

        $this->info('Running time entries:');
        foreach ($running as $entry) {
            $this->line("  #{$entry['id']} {$entry['client']} — ".($entry['task'] ?? 'no task')." since {$entry['started']}");
        }
        if ($running->isEmpty()) {
            $this->line('  none');
        }

        return self::SUCCESS;
    }
}

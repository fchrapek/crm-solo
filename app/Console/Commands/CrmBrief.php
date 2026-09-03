<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\AmbiguousReferenceException;
use App\Services\Agent\ClientBrief as ClientBriefData;
use App\Services\Agent\CrmEntityResolver;
use App\Services\Agent\ReferenceException;
use Illuminate\Console\Command;

/**
 * The agent-facing client context dump — the client-side equivalent of
 * CRM_TASK.md. One call answers "where does this client stand" so an agent
 * never has to screen-scrape the UI: identity, relationship state, retainer
 * positions, hours this month vs limit, open tasks, latest report, recent
 * journal. Part of the crm:* verb contract (docs/development/
 * agent-crm-interface.md).
 */
final class CrmBrief extends Command
{
    protected $signature = 'crm:brief {client : Client id or name fragment} {--json : Machine-readable output}';

    protected $description = 'Full client context for agents: status, retainer, hours, tasks, latest report, journal';

    public function handle(CrmEntityResolver $resolver, ClientBriefData $brief): int
    {
        try {
            $client = $resolver->client((string) $this->argument('client'));
        } catch (ReferenceException $e) {
            $this->error($e->getMessage());
            if ($e instanceof AmbiguousReferenceException) {
                $this->line($e->candidateLines());
            }

            return self::FAILURE;
        }

        $data = $brief->for($client);

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("# {$data['name']} (#{$data['id']}) — {$data['status']}");
        $this->line("Cooperation: {$data['cooperation_type']} · Segment: {$data['segment']} · Currency: {$data['currency']}");
        $this->line("Hours this month: {$data['hours']['month']} / {$data['hours']['contracted']}");
        foreach ($data['retainers'] as $r) {
            $this->line("Retainer: {$r['label']} — {$r['monthly_hours']}h".($r['monthly_fee'] !== null ? " / {$r['monthly_fee']}" : ''));
        }
        $this->line($data['latest_report'] ? "Latest report: {$data['latest_report']['period']} ({$data['latest_report']['status']})" : 'Latest report: none');
        $this->newLine();
        $this->info('Open tasks:');
        foreach ($data['open_tasks'] as $task) {
            $this->line("  #{$task['id']} [{$task['project']}] {$task['name']}".($task['due'] ? " (due {$task['due']})" : ''));
        }
        if (count($data['open_tasks']) === 0) {
            $this->line('  none');
        }
        $this->newLine();
        $this->info('Journal (latest 5):');
        foreach ($data['journal'] as $entry) {
            $this->line("  {$entry['at']} — {$entry['change']}".($entry['note'] ? ": {$entry['note']}" : ''));
        }

        return self::SUCCESS;
    }
}

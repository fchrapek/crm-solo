<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
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
#[AccountScope(AccountScope::ACTING)]
final class CrmBrief extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:brief {client : Client id or name fragment} {--json : Machine-readable output}';

    protected $description = 'Full client context for agents: status, retainer, hours, tasks, latest report, journal';

    public function handle(CrmEntityResolver $resolver, ClientBriefData $brief): int
    {
        try {
            $client = $resolver->client((string) $this->argument('client'), $this->actingIdentity()->account->id);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        $data = $brief->for($client);

        if ($this->option('json')) {
            $this->raw($this->encodeJson($data));

            return self::SUCCESS;
        }

        $this->raw("# Client #{$data['id']} - ".$this->literal($data['status']));
        $this->raw('Cooperation: '.$this->literal($data['cooperation_type']).' · Segment: '.$this->literal($data['segment']).' · Currency: '.$this->literal($data['currency']));
        $this->raw("Hours this month: {$data['hours']['month']} / {$data['hours']['contracted']}");
        foreach ($data['retainers'] as $r) {
            $this->raw('Retainer: '.$this->literal($r['label'])." - {$r['monthly_hours']}h".($r['monthly_fee'] !== null ? " / {$r['monthly_fee']}" : ''));
        }
        $this->raw($data['latest_report'] ? "Latest report: {$data['latest_report']['period']} ({$data['latest_report']['status']})" : 'Latest report: none');
        $this->newLine();

        // The client name (often imported) and task titles (often from cards) are fenced.
        $lines = ['Client: '.$this->literal($data['name']), 'Open tasks:'];
        foreach ($data['open_tasks'] as $task) {
            $lines[] = "  #{$task['id']} [".$this->literal($task['project']).'] '.$this->literal($task['name']).($task['due'] ? " (due {$task['due']})" : '');
        }
        if (count($data['open_tasks']) === 0) {
            $lines[] = '  none';
        }
        $this->fenced($lines);

        $this->newLine();
        $this->raw('Journal (latest 5):');
        foreach ($data['journal'] as $entry) {
            $this->raw("  {$entry['at']} - ".$this->literal($entry['change']).($entry['note'] ? ': '.$this->literal($entry['note']) : ''));
        }

        return self::SUCCESS;
    }
}

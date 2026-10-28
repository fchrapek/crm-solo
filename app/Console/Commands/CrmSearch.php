<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Services\Search\GlobalSearch;
use Illuminate\Console\Command;

/**
 * Agent verb: the ⌘K search, everything the owner can open grouped by type,
 * five per group or up to twenty for one group.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmSearch extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'crm:search
        {query : At least two characters}
        {--group= : Only clients, tasks, contacts, leads, reports or invoices}
        {--archived : Include archived clients, done tasks and deleted records}
        {--json : Machine-readable output}';

    protected $description = 'Search clients, tasks, contacts, leads, reports and invoices';

    public function handle(GlobalSearch $search): int
    {
        $result = $search->search(
            $this->actingIdentity()->account->id,
            (string) $this->argument('query'),
            (bool) $this->option('archived'),
            is_string($this->option('group')) ? $this->option('group') : null,
        );

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        if ($result['groups'] === []) {
            $this->raw('No results.');

            return self::SUCCESS;
        }

        $lines = [];
        foreach ($result['groups'] as $group) {
            $lines[] = "{$group['group']} ({$group['total']})";
            foreach ($group['items'] as $item) {
                $lines[] = "  #{$item['id']} ".$this->literal($item['title'])
                    .($item['client'] !== null ? ' · '.$this->literal($item['client']) : '')
                    .($item['archived'] ? ' (archived)' : '')
                    .($item['url'] !== null ? " · {$item['url']}" : '');
            }
        }
        $this->fenced($lines);

        return self::SUCCESS;
    }
}

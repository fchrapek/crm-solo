<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Plain-text lines for task lists, client lists and day plans, on the shared
 * literal renderer from AgentConsoleOutput; the caller fences them.
 */
trait PrintsAgentLists
{
    use AgentConsoleOutput;

    /**
     * @param  array<string, mixed>  $task  a TaskRecord::listItem
     */
    private function taskLine(array $task): string
    {
        $flags = array_filter([
            $task['done'] ? 'done' : null,
            $task['archived'] ? 'archived' : null,
            $task['trello'] ? 'Trello' : null,
            $task['is_overdue'] ? 'OVERDUE' : null,
        ]);

        return "  #{$task['id']} [".$this->literal($task['client'] ?? 'no client').' / '.$this->literal($task['project'] ?? 'no project').'] '
            .$this->literal($task['name']).' · '.$this->literal($task['list'] ?? '-')
            .($task['due'] !== null ? " · due {$task['due']}" : '')
            .($flags !== [] ? ' ('.implode(', ', $flags).')' : '');
    }

    /**
     * @param  array<string, mixed>  $client  a ClientDirectory::item
     */
    private function clientLine(array $client): string
    {
        $retainer = $client['retainer'];

        return "  #{$client['id']} ".$this->literal($client['name'])
            .' · '.($client['type'] ?? 'no type').' · '.$client['stage'].' · '.$client['billing_mode']
            .($retainer !== null ? ' · retainer '.($retainer['monthly_hours'] ?? '-').' h, '.($retainer['monthly_fee'] ?? '-').' '.($retainer['currency'] ?? '') : '')
            .($client['archived'] ? ' (archived)' : '');
    }

    /**
     * @param  array<string, mixed>  $entry  a TimeEntryRecord::item
     */
    private function timeEntryLine(array $entry): string
    {
        $flags = array_filter([
            $entry['running'] ? 'running' : null,
            $entry['billable'] ? null : 'not billable',
            $entry['editable'] ? null : 'settled',
        ]);
        $end = match (true) {
            $entry['end'] === null => 'now',
            mb_substr((string) $entry['end'], 0, 10) === mb_substr((string) $entry['start'], 0, 10) => mb_substr((string) $entry['end'], 11),
            default => (string) $entry['end'],
        };

        return "  #{$entry['id']} {$entry['start']}-{$end} {$entry['minutes']} min ["
            .$this->literal($entry['client'] ?? 'no client').' / '.$this->literal($entry['project'] ?? 'no project').'] '
            .($entry['task_id'] !== null ? "task #{$entry['task_id']} ".$this->literal($entry['task']) : 'no task')
            .($entry['description'] !== null ? ' · '.$this->literal($entry['description']) : '')
            .($flags !== [] ? ' ('.implode(', ', $flags).')' : '');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function planLines(array $plan): array
    {
        $lines = ['Plan for '.$plan['day'].' ('.$plan['date'].'), '.count($plan['picks']).' of '.$plan['max_picks'].($plan['closed'] ? ', day closed' : '').':'];
        foreach ($plan['picks'] as $pick) {
            $lines[] = "  #{$pick['task_id']} [".$this->literal($pick['client'] ?? 'no client').'] '.$this->literal($pick['name'])
                .($pick['done'] ? ' (done)' : '').($pick['minutes'] > 0 ? " {$pick['minutes']} min" : '');
        }
        if ($plan['picks'] === []) {
            $lines[] = '  none';
        }

        return $lines;
    }
}

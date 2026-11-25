<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Plain-text lines for a project's Trello link, on the shared literal
 * renderer; the caller fences them, since board and list names come from Trello.
 */
trait PrintsTrelloLinks
{
    use AgentConsoleOutput;

    /**
     * @param  array<string, mixed>  $link  a TrelloLinks item
     * @return list<string>
     */
    private function linkLines(array $link): array
    {
        $board = $link['board'];
        $mapping = $link['mapping'];
        $flags = array_filter([
            $link['archived'] ? 'archived' : null,
            $board['visible'] === false ? 'board not visible to the Trello connection' : null,
        ]);

        $lines = ["  #{$link['project_id']} [".$this->literal($link['client'] ?? 'no client').' / '.$this->literal($link['project']).'] board '
            .$this->literal($board['id']).($board['name'] !== null ? ' '.$this->literal($board['name']) : '')
            .($board['url'] !== null ? ' '.$this->literal($board['url']) : '')
            ." · {$mapping['lists']} lists, {$mapping['mapped']} mapped, {$mapping['done_lists']} Done"
            .' · sync '.$link['sync']['state']
            .($flags !== [] ? ' ('.implode(', ', $flags).')' : '')];

        foreach ($link['lists'] as $list) {
            $lines[] = '    '.$this->literal($list['name']).' -> '.$this->literal($list['lane'] ?? 'not mapped yet');
        }

        return $lines;
    }
}

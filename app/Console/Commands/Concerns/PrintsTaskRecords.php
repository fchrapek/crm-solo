<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Plain-text output for the task verbs, on the shared literal renderer and
 * fence from AgentConsoleOutput.
 */
trait PrintsTaskRecords
{
    use AgentConsoleOutput;

    /**
     * CRM facts first, then everything that names or describes the task inside
     * one fence: title, client, project, list, link, description, checklists,
     * comments, files and links, and a fetch error (it can quote Trello).
     *
     * @param  array<string, mixed>  $record
     */
    private function printRecord(array $record): void
    {
        $this->raw("# Task #{$record['id']} [{$record['state']}] ({$record['source']['type']})"
            .($record['due'] !== null ? ' due '.$record['due'].($record['is_overdue'] ? ' (OVERDUE)' : '') : ''));
        $this->raw('Ready: '.($record['readiness']['ready'] ? 'yes' : 'no, missing '.implode(', ', $record['readiness']['missing'])));
        $this->raw('Time: '.$record['time']['minutes'].' min logged'.($record['time']['running_timer'] !== null ? ', timer #'.$record['time']['running_timer']['entry_id'].' running' : ''));
        if ($record['project']['repository_path'] ?? null) {
            $this->raw('Repo: '.$this->literal($record['project']['repository_path']));
        }

        $this->newLine();
        $this->printBriefFields($record['brief']);
        $this->newLine();

        $lines = [
            'Title: '.$this->literal($record['name']),
            'Client: '.$this->literal($record['client']['name'] ?? 'no client').' · Project: '.$this->literal($record['project']['name'] ?? 'no project'),
        ];
        if ($record['source']['list'] !== null || $record['source']['card_url'] !== null) {
            $lines[] = 'List: '.$this->literal($record['source']['list'] ?? '-').($record['source']['card_url'] !== null ? ' · Card: '.$this->literal($record['source']['card_url']) : '');
        }
        if ($record['source']['fetch_error'] !== null) {
            $lines[] = 'Card details not refreshed: '.$this->literal($record['source']['fetch_error']);
        }
        $lines[] = 'Description:';
        $lines[] = $record['description']['markdown'] !== null ? $this->literal($record['description']['markdown'], multiline: true) : '(none)';
        foreach ($record['checklists'] as $checklist) {
            $lines[] = 'Checklist: '.$this->literal($checklist['name']);
            foreach ($checklist['items'] as $item) {
                $lines[] = '  ['.($item['done'] ? 'x' : ' ').'] '.$this->literal($item['name']);
            }
        }
        foreach ($record['comments'] as $comment) {
            $lines[] = 'Comment by '.$this->literal($comment['author'] ?? 'unknown').($comment['at'] !== null ? " at {$comment['at']}" : '').':';
            $lines[] = '  '.str_replace("\n", "\n  ", $this->literal($comment['text'], multiline: true));
        }
        foreach ($record['attachments'] as $attachment) {
            $lines[] = 'Attachment'.($attachment['id'] !== null ? " #{$attachment['id']}" : '').': '.$this->literal($attachment['name'])
                .($attachment['path'] !== null ? ' -> '.$this->literal($attachment['path']) : ' ('.$attachment['status'].': '.$this->literal($attachment['reason']).')');
        }
        foreach ($record['links'] as $link) {
            $lines[] = 'Link: '.$this->literal($link['url']).($link['text'] !== null ? ' ('.$this->literal($link['text']).')' : '');
        }

        $this->fenced($lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function printBrief(array $payload): void
    {
        $this->raw("# Brief for task #{$payload['task_id']}");
        $this->fenced(['Title: '.$this->literal($payload['name'])]);
        $this->printBriefFields($payload['brief']);
        $this->raw('Ready: '.($payload['readiness']['ready'] ? 'yes' : 'no, missing '.implode(', ', $payload['readiness']['missing'])));
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function printBriefFields(array $brief): void
    {
        $this->raw('Brief (CRM):');
        foreach (['where' => 'Where', 'done_when' => 'Done when', 'constraints' => 'Constraints', 'notes' => 'Notes'] as $field => $label) {
            $this->raw("  {$label}: ".($brief[$field] !== null ? $this->literal($brief[$field], multiline: true) : '-'));
        }
        if ($brief['drafted_by'] !== null) {
            $this->raw('  Drafted by '.$this->literal($brief['drafted_by']['name'] ?? 'unknown').' via '.$brief['drafted_by']['via'].' at '.$brief['drafted_at']);
        }
        $this->raw('  '.($brief['confirmed_at'] !== null
            ? 'Confirmed at '.$brief['confirmed_at']
            : ($brief['unconfirmed'] !== [] ? 'Not confirmed: '.implode(', ', $brief['unconfirmed']) : 'Empty')));
    }
}

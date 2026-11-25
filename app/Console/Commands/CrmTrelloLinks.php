<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsTrelloLinks;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TrelloLinks;
use Illuminate\Console\Command;

/**
 * Agent verb: the account's projects linked to Trello boards, with each
 * board's lists, their lanes and the sync state; with --boards also every
 * board the connection can see, as the connect dialog's picker lists them.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTrelloLinks extends Command
{
    use PrintsTrelloLinks;

    protected $signature = 'crm:trello-links
        {--client= : Only this client\'s projects (id or name fragment)}
        {--project= : Only this project (id or name fragment)}
        {--archived : Include archived projects}
        {--boards : Also list every board the Trello connection can see, with its link status}
        {--json : Machine-readable output}';

    protected $description = 'List projects linked to Trello boards: board, lists and lanes, sync state';

    public function handle(TrelloLinks $links): int
    {
        try {
            $result = $links->list($this->actingIdentity()->account->id, [
                'client' => $this->option('client'),
                'project' => $this->option('project'),
                'archived' => (bool) $this->option('archived'),
                'boards' => (bool) $this->option('boards'),
            ]);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $this->raw('Trello: '.($result['connected'] ? 'connected' : 'not connected')
            .', last sync '.($result['synced_at'] ?? 'never').'.');
        if ($result['sync_error'] !== null) {
            $this->raw('Last sync failed:');
            $this->fenced(['  '.$this->literal($result['sync_error'])]);
        }
        if ($result['boards_error'] !== null) {
            $this->raw('WARNING boards not read: '.$this->literal($result['boards_error']));
        }

        $this->raw("Links: {$result['total']}.");
        $lines = array_merge(...array_map(fn (array $link): array => $this->linkLines($link), $result['links']));
        $this->fenced($lines !== [] ? $lines : ['  none']);

        if (array_key_exists('boards', $result)) {
            $this->raw('Boards: '.count($result['boards']).'.');
            $boards = array_map(fn (array $board): string => '  '.$this->literal($board['id']).' '.$this->literal($board['name'])
                .($board['workspace'] !== null ? ' ('.$this->literal($board['workspace']).')' : '')
                .' · '.$board['linked_status'].($board['linked_project_id'] !== null ? " #{$board['linked_project_id']}" : ''), $result['boards']);
            $this->fenced($boards !== [] ? $boards : ['  none']);
        }

        return self::SUCCESS;
    }
}

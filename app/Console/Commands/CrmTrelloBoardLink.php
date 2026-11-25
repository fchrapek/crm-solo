<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\PrintsTrelloLinks;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TrelloLinks;
use Illuminate\Console\Command;

/**
 * Agent verb: link a project to a board that already exists on Trello and
 * pull its cards, as the connect dialog's "Link existing board" does.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTrelloBoardLink extends Command
{
    use PrintsTrelloLinks;

    protected $signature = 'crm:trello-board-link
        {project : Project id or name fragment}
        {board : Board id, short link, URL or part of its name}
        {--client= : Look the project up under this client only}
        {--json : Machine-readable output}';

    protected $description = 'Link a project to an existing Trello board and pull its cards';

    public function handle(TrelloLinks $links): int
    {
        try {
            $result = $links->linkBoard($this->actingIdentity()->account->id, [
                'project' => (string) $this->argument('project'),
                'board' => (string) $this->argument('board'),
                'client' => $this->option('client'),
            ]);
        } catch (ReferenceException $e) {
            return $this->referenceFailure($e);
        } catch (AgentRefusal $e) {
            return $this->refusal($e);
        }

        if ($this->wantsJson()) {
            $this->raw($this->encodeJson($result));

            return self::SUCCESS;
        }

        $link = $result['link'];
        $this->raw("Linked project #{$link['project_id']} to Trello board ".$this->literal($link['board']['id']).'.'
            .($result['synced'] ? " The first sync pulled {$result['card_tasks']} cards." : '')
            .($result['adopted_project_id'] !== null ? " Took the board and its tasks over from project #{$result['adopted_project_id']}." : ''));
        foreach ($result['warnings'] as $warning) {
            $this->raw('WARNING '.$this->literal($warning));
        }
        $this->fenced($this->linkLines($link));

        return self::SUCCESS;
    }
}

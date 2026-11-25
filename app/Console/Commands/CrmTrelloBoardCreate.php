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
 * Agent verb: make a Trello board for a project and link it, as the connect
 * dialog's "Create new board" does: the project's name, the five default
 * lists and the priority labels.
 */
#[AccountScope(AccountScope::ACTING)]
final class CrmTrelloBoardCreate extends Command
{
    use PrintsTrelloLinks;

    protected $signature = 'crm:trello-board-create
        {project : Project id or name fragment}
        {--client= : Look the project up under this client only}
        {--json : Machine-readable output}';

    protected $description = 'Create a Trello board for a project and link it';

    public function handle(TrelloLinks $links): int
    {
        try {
            $result = $links->createBoard($this->actingIdentity()->account->id, [
                'project' => (string) $this->argument('project'),
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

        $this->raw("Created a Trello board for project #{$result['link']['project_id']} and linked it.");
        $this->fenced($this->linkLines($result['link']));

        return self::SUCCESS;
    }
}

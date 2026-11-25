<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\AgentRefusal;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TrelloLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new board on Trello for a project and link it, the same as "Create new board" in the project\'s Trello dialog: the board takes the project\'s name, the lists Backlog, To-Do, Doing, Testing and Done and three priority labels. This writes to Trello. Refusals: "already_linked" (the project has a board), "trello_not_connected", "trello_failed". To use a board that already exists, call trello_board_link instead. Confirm the project with the user before calling.')]
final class TrelloBoardCreateTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'trello_board_create';

    public function handle(Request $request, TrelloLinks $links): Response
    {
        $validated = $request->validate([
            'project' => ['required', 'string'],
            'client' => ['nullable', 'string'],
        ], [
            'project.required' => 'Pass the project to make a board for.',
        ]);

        try {
            return $this->payload($links->createBoard($this->identity()->account->id, $validated));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        } catch (AgentRefusal $e) {
            return $this->refusalError($e);
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or name fragment.')->required(),
            'client' => $schema->string()->description('Look the project up under this client only: id or name fragment.'),
        ];
    }
}

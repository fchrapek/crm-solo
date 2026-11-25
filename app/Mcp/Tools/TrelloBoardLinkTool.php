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

#[Description('Link a project to a board that already exists on the connected Trello account and pull its lists and cards, the same as "Link existing board" in the project\'s Trello dialog. Nothing is written to Trello. The board is its id, short link, URL or part of its name; several boards matching a name return "ambiguous_reference" with the board ids. A board held by a project without a client is taken over from it, tasks included. Refusals: "already_linked", "board_linked_elsewhere" (another client\'s project holds it), "trello_not_connected", "trello_failed". When the link holds but the first sync did not finish, "synced" is false and "warnings" says why. Confirm the project and the board with the user before calling.')]
final class TrelloBoardLinkTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'trello_board_link';

    public function handle(Request $request, TrelloLinks $links): Response
    {
        $validated = $request->validate([
            'project' => ['required', 'string'],
            'board' => ['required', 'string'],
            'client' => ['nullable', 'string'],
        ], [
            'project.required' => 'Pass the project to link the board to.',
            'board.required' => 'Pass the board: its id, short link, URL or part of its name.',
        ]);

        try {
            return $this->payload($links->linkBoard($this->identity()->account->id, $validated));
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
            'board' => $schema->string()->description('Board id, short link, URL or part of its name.')->required(),
            'client' => $schema->string()->description('Look the project up under this client only: id or name fragment.'),
        ];
    }
}

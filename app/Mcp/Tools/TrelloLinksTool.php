<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Agent\ReferenceException;
use App\Services\Agent\TrelloLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the projects linked to Trello boards: client, board id, name and URL, each Trello list with the CRM lane it maps to, and the sync state (the account\'s last Trello sync). Narrow it to a client or a project. With boards, also every board the Trello connection can see, marked available, orphan (held by a project without a client, which gives it up on link) or linked; use it to pick a board for trello_board_link.')]
final class TrelloLinksTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'trello_links';

    public function handle(Request $request, TrelloLinks $links): Response
    {
        $validated = $request->validate([
            'client' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
            'archived' => ['nullable', 'boolean'],
            'boards' => ['nullable', 'boolean'],
        ]);

        try {
            return $this->payload($links->list($this->identity()->account->id, $validated));
        } catch (ReferenceException $e) {
            return $this->referenceError($e);
        }
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Only this client\'s projects: id or name fragment.'),
            'project' => $schema->string()->description('Only this project: id or name fragment.'),
            'archived' => $schema->boolean()->description('Include archived projects, which the sync skips.'),
            'boards' => $schema->boolean()->description('Also list every board the Trello connection can see, with its link status.'),
        ];
    }
}

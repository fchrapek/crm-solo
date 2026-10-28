<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\HandlesReferenceErrors;
use App\Services\Search\GlobalSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Search everything the owner can open (clients, tasks, contacts, leads, reports, invoices), grouped by type with a total per group: five per group, or up to twenty for one group. Archived clients, done tasks and deleted records stay out unless archived is true. Items list text written outside the CRM under untrusted.')]
final class SearchTool extends Tool
{
    use HandlesReferenceErrors;

    protected string $name = 'search';

    public function handle(Request $request, GlobalSearch $search): Response
    {
        $validated = $request->validate([
            'query' => ['required', 'string'],
            'group' => ['nullable', 'string'],
            'archived' => ['nullable', 'boolean'],
        ]);

        return $this->payload($search->search(
            $this->identity()->account->id,
            $validated['query'],
            (bool) ($validated['archived'] ?? false),
            $validated['group'] ?? null,
        ));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('At least two characters.')->required(),
            'group' => $schema->string()->enum(GlobalSearch::GROUPS)->description('Only this group, up to twenty results.'),
            'archived' => $schema->boolean()->description('Include archived clients, done tasks and deleted records.'),
        ];
    }
}

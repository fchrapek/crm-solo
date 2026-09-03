<?php

declare(strict_types=1);

namespace App\Services\TaskSources\Providers;

use App\Models\Integration;
use App\Models\Project;
use App\Services\Integrations\Trello\TrelloOnboardingService;
use App\Services\Integrations\Trello\TrelloService;
use App\Services\TaskSources\TaskSourceProviderInterface;
use Illuminate\Support\Arr;

/**
 * Trello as a task source. Thin adapter over TrelloService (API + sync) and
 * TrelloOnboardingService (board creation / linking) — all behavior lives in
 * those services; this class only maps the generic provider vocabulary onto
 * them. Containers are Trello boards.
 *
 * TrelloService is constructed per call (not in the constructor) because it
 * binds to a specific Integration's credentials and throws when they are
 * missing — the provider itself must stay resolvable without an integration.
 */
final class TrelloTaskSourceProvider implements TaskSourceProviderInterface
{
    public function __construct(
        private readonly TrelloOnboardingService $onboarding,
    ) {}

    public function key(): string
    {
        return 'trello';
    }

    public function syncProject(Project $project, Integration $integration): array
    {
        return (new TrelloService($integration))->syncBoard(
            $project->trello_board_id,
            $project->account_id,
            $project->client_id,
        );
    }

    public function syncAll(Integration $integration): array
    {
        return (new TrelloService($integration))->syncAllBoards($integration->account_id);
    }

    public function listContainers(Integration $integration): array
    {
        return collect((new TrelloService($integration))->fetchBoards())
            ->map(fn (array $board): array => [
                'id' => $board['id'] ?? null,
                'name' => $board['name'] ?? '(unnamed)',
                'url' => $board['url'] ?? null,
                'workspace' => $board['organization']['displayName'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function connectNewContainer(Project $project, Integration $integration): Project
    {
        return $this->onboarding->connectExistingProject($project, $integration);
    }

    public function linkExistingContainer(Project $project, Integration $integration, string $containerId): Project
    {
        return $this->onboarding->linkExistingBoard($project, $integration, $containerId);
    }

    public function disconnect(Project $project): void
    {
        $project->update([
            'trello_board_id' => null,
            'trello_url' => null,
            'settings' => Arr::except($project->settings ?? [], [
                'trello_workspace',
                'trello_lists',
                'trello_done_list_id',
                'trello_list_mapping',
                'trello_priority_labels',
            ]),
        ]);
    }
}

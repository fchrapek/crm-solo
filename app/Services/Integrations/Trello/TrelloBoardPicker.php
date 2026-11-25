<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use App\Models\Integration;
use App\Models\Project;
use App\Services\TaskSources\TaskSourceRegistry;

/**
 * The boards the connect dialog offers for linking: every board the token can
 * see, each marked available, orphan (a project without a client holds it and
 * gives it up on link) or linked (another client's project holds it).
 */
final readonly class TrelloBoardPicker
{
    public function __construct(
        private TaskSourceRegistry $taskSources,
    ) {}

    /**
     * @return list<array{id: string|null, name: string, url: string|null, workspace: string|null, linked_status: string, linked_client_name: string|null, linked_project_id: int|null}>
     */
    public function boards(Integration $integration): array
    {
        $boards = collect($this->taskSources->get('trello')->listContainers($integration));

        $linkedProjects = Project::with('client:id,name')
            ->where('account_id', $integration->account_id)
            ->whereNotNull('trello_board_id')
            ->get()
            ->keyBy('trello_board_id');

        return $boards->map(function (array $board) use ($linkedProjects): array {
            $linked = $board['id'] !== null ? $linkedProjects->get($board['id']) : null;

            return [
                ...$board,
                'linked_status' => match (true) {
                    $linked === null => 'available',
                    $linked->client_id === null => 'orphan',
                    default => 'linked',
                },
                'linked_client_name' => $linked?->client?->name,
                'linked_project_id' => $linked !== null ? (int) $linked->id : null,
            ];
        })->values()->all();
    }
}

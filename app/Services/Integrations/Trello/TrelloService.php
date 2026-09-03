<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TrelloService
{
    /** Maps Trello label colors to priority levels. */
    public const PRIORITY_COLORS = [
        'red' => 'high',
        'yellow' => 'medium',
        'green' => 'low',
    ];

    private const BASE_URL = 'https://api.trello.com/1';

    private PendingRequest $http;

    public function __construct(
        private readonly Integration $integration
    ) {
        $settings = $this->integration->settings ?? [];
        $apiKey = $settings['trello_api_key'] ?? config('services.trello.api_key');
        $apiToken = $this->integration->api_key;

        if (! $apiKey || ! $apiToken) {
            throw new RuntimeException('Trello API key or token is not configured.');
        }

        $this->http = Http::baseUrl(self::BASE_URL)
            ->withQueryParameters([
                'key' => $apiKey,
                'token' => $apiToken,
            ])
            ->timeout(30);
    }

    public function testConnection(): bool
    {
        $response = $this->http->get('/members/me');

        return $response->ok();
    }

    public function fetchBoards(): array
    {
        $response = $this->http->get('/members/me/boards', [
            'fields' => 'name,desc,url,closed,idOrganization',
            'filter' => 'open',
            'organization' => 'true',
            'organization_fields' => 'displayName',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Trello boards: '.$response->body());
        }

        return $response->json();
    }

    public function fetchLists(string $boardId): array
    {
        $response = $this->http->get("/boards/{$boardId}/lists", [
            'fields' => 'name,pos,closed',
            'filter' => 'open',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Trello lists: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Fetch cards for a board (open + archived). The `closed` flag is mirrored
     * onto Task::archived_at by syncBoard, so consumers can show or hide
     * archived cards without losing them on the next sync.
     */
    public function fetchCards(string $boardId): array
    {
        $response = $this->http->get("/boards/{$boardId}/cards", [
            'fields' => 'name,desc,idList,pos,due,dueComplete,url,labels,closed',
            'filter' => 'all',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Trello cards: '.$response->body());
        }

        return $response->json();
    }

    public function createLabel(string $boardId, string $name, string $color): array
    {
        $response = $this->http->post('/labels', [
            'idBoard' => $boardId,
            'name' => $name,
            'color' => $color,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to create Trello label: '.$response->body());
        }

        return $response->json();
    }

    public function fetchLabels(string $boardId): array
    {
        $response = $this->http->get("/boards/{$boardId}/labels");

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Trello labels: '.$response->body());
        }

        return $response->json();
    }

    public function createBoard(string $name): array
    {
        $response = $this->http->post('/boards', [
            'name' => $name,
            'defaultLists' => 'false',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to create Trello board: '.$response->body());
        }

        return $response->json();
    }

    public function createList(string $boardId, string $name, ?int $position = null): array
    {
        $params = [
            'idBoard' => $boardId,
            'name' => $name,
        ];

        if ($position !== null) {
            $params['pos'] = $position;
        }

        $response = $this->http->post('/lists', $params);

        if ($response->failed()) {
            throw new RuntimeException('Failed to create Trello list: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Sync a specific board's cards into the local database.
     */
    public function syncBoard(string $boardId, int $accountId, ?int $clientId = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'errors' => 0];

        $boardResponse = $this->http->get("/boards/{$boardId}", [
            'fields' => 'name,desc,url',
        ]);

        if ($boardResponse->failed()) {
            throw new RuntimeException('Failed to fetch board: '.$boardResponse->body());
        }

        $boardData = $boardResponse->json();

        // First sync seeds name + description from Trello; subsequent syncs
        // preserve the user's local edits (renames in CRM stay put). Only the
        // trello_url is refreshed each time, since Trello owns it.
        $project = Project::firstOrCreate(
            [
                'account_id' => $accountId,
                'trello_board_id' => $boardId,
            ],
            [
                'client_id' => $clientId,
                'name' => $boardData['name'],
                'description' => $boardData['desc'] ?? null,
                'trello_url' => $boardData['url'] ?? null,
            ]
        );

        if (! $project->wasRecentlyCreated) {
            $project->update([
                'trello_url' => $boardData['url'] ?? $project->trello_url,
            ]);
        }

        $lists = $this->fetchLists($boardId);
        $listNames = collect($lists)->keyBy('id')->map(fn ($l) => $l['name']);

        $settings = $project->settings ?? [];
        $settings['trello_lists'] = collect($lists)->map(fn ($l) => ['id' => $l['id'], 'name' => $l['name']])->values()->all();

        // Auto-detect or backfill the canonical-lane mapping. Existing user
        // configuration in trello_list_mapping is preserved; we only fill in
        // entries for lists that aren't yet mapped, plus migrate the legacy
        // trello_done_list_id pointer into the mapping (single-list → 'Done').
        $mapping = $settings['trello_list_mapping'] ?? [];
        $detected = TrelloListMapper::autoDetectMapping($settings['trello_lists']);
        foreach ($detected as $listId => $lane) {
            if (! array_key_exists($listId, $mapping)) {
                $mapping[$listId] = $lane;
            }
        }
        $legacyDoneId = $settings['trello_done_list_id'] ?? null;
        if ($legacyDoneId !== null && isset($mapping[$legacyDoneId])) {
            $mapping[$legacyDoneId] = TrelloListMapper::LANE_DONE;
        }
        $settings['trello_list_mapping'] = $mapping;
        $project->update(['settings' => $settings]);

        $listToCanonical = $mapping;

        $cards = $this->fetchCards($boardId);

        foreach ($cards as $card) {
            try {
                $cardLabels = collect($card['labels'] ?? []);
                $priority = $this->extractPriorityFromLabels($cardLabels);
                $projectLabels = $this->extractProjectLabels($cardLabels);

                $canonicalLane = $listToCanonical[$card['idList']] ?? TrelloListMapper::LANE_BACKLOG;
                $closed = (bool) ($card['closed'] ?? false);

                // Archive state mirrors Trello's `closed` flag. Existing
                // archived_at timestamps are preserved when the card is still
                // closed (don't bump the date on every sync).
                $existing = Task::where('project_id', $project->id)
                    ->where('trello_card_id', $card['id'])
                    ->first();
                $archivedAt = $closed
                    ? ($existing?->archived_at ?? now())
                    : null;

                $task = Task::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'trello_card_id' => $card['id'],
                    ],
                    [
                        'trello_list_id' => $card['idList'] ?? null,
                        'name' => $card['name'],
                        'description' => $card['desc'] ?? null,
                        'list_name' => $canonicalLane,
                        'position' => min((int) ($card['pos'] ?? 0), 2147483647),
                        'due_date' => $card['due'] ?? null,
                        'labels' => $projectLabels ?: null,
                        'trello_url' => $card['url'] ?? null,
                        'is_completed' => ($card['dueComplete'] ?? false) || $canonicalLane === TrelloListMapper::LANE_DONE,
                        'priority' => $priority,
                        'archived_at' => $archivedAt,
                    ]
                );

                $task->wasRecentlyCreated ? $stats['created']++ : $stats['updated']++;
            } catch (Exception $e) {
                Log::warning('Failed to sync Trello card', [
                    'card_id' => $card['id'],
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        $this->integration->update(['last_synced_at' => now()]);

        return $stats;
    }

    /**
     * Sync the boards this CRM has adopted.
     *
     * NOT every board the token can see. Trello hands back everything Filip has
     * ever been added to, and syncing all of it buried the CRM in client-less
     * projects nobody asked for. A board earns a sync by being adopted first —
     * the connect dialog, `trello:adopt`, or onboarding's board creation.
     */
    public function syncAllBoards(int $accountId): array
    {
        $totalStats = ['boards' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0];

        $boards = $this->fetchBoards();

        foreach ($boards as $board) {
            try {
                $existingProject = Project::where('trello_board_id', $board['id'])
                    ->where('account_id', $accountId)
                    ->first();

                // Never heard of it, or explicitly thrown away. A null client_id
                // is NOT a disqualifier: the connect dialog creates the project
                // before the client mapping lands.
                if ($existingProject === null || $existingProject->isArchived()) {
                    $totalStats['skipped']++;

                    continue;
                }

                $stats = $this->syncBoard($board['id'], $accountId, $existingProject?->client_id);

                $workspaceName = $board['organization']['displayName'] ?? null;
                if ($workspaceName) {
                    $project = Project::where('trello_board_id', $board['id'])
                        ->where('account_id', $accountId)
                        ->first();
                    $project?->update(['settings' => array_merge($project->settings ?? [], ['trello_workspace' => $workspaceName])]);
                }

                $totalStats['boards']++;
                $totalStats['created'] += $stats['created'];
                $totalStats['updated'] += $stats['updated'];
                $totalStats['errors'] += $stats['errors'];
            } catch (Exception $e) {
                Log::warning('Failed to sync Trello board', [
                    'board_id' => $board['id'],
                    'error' => $e->getMessage(),
                ]);
                $totalStats['errors']++;
            }
        }

        return $totalStats;
    }

    /**
     * Extract priority from label colors (red=high, yellow=medium, green=low).
     * Returns the highest priority found, or null if no priority label.
     */
    private function extractPriorityFromLabels(\Illuminate\Support\Collection $labels): ?string
    {
        $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
        $highest = null;
        $highestRank = 0;

        foreach ($labels as $label) {
            $color = $label['color'] ?? null;
            $priority = self::PRIORITY_COLORS[$color] ?? null;

            if ($priority && $priorityOrder[$priority] > $highestRank) {
                $highest = $priority;
                $highestRank = $priorityOrder[$priority];
            }
        }

        return $highest;
    }

    /**
     * Extract non-priority labels as project/category labels.
     * Returns label names that are NOT priority colors (red/yellow/green).
     */
    private function extractProjectLabels(\Illuminate\Support\Collection $labels): array
    {
        return $labels
            ->filter(fn ($label) => ! isset(self::PRIORITY_COLORS[$label['color'] ?? '']))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class TrelloOnboardingService
{
    private const DEFAULT_LISTS = [
        'Backlog',
        'To-Do',
        'Doing',
        'Testing',
        'Done',
    ];

    private const PRIORITY_LABELS = [
        ['name' => 'High', 'color' => 'red'],
        ['name' => 'Medium', 'color' => 'yellow'],
        ['name' => 'Low', 'color' => 'green'],
    ];

    /**
     * Create a Trello board for a new client and link it as a project.
     */
    public function onboard(Client $client, Integration $integration): Project
    {
        if (! $integration->isConfigured()) {
            throw new RuntimeException('Trello integration is not configured.');
        }

        $project = Project::create([
            'account_id' => $client->account_id,
            'client_id' => $client->id,
            'name' => $client->name,
        ]);

        return $this->attachBoard($project, $integration, $project->name);
    }

    /**
     * Attach a fresh Trello board to an *existing* (private) project. Used when
     * the user has already created a project and now wants to add a board to it.
     */
    public function connectExistingProject(Project $project, Integration $integration): Project
    {
        if (! $integration->isConfigured()) {
            throw new RuntimeException('Trello integration is not configured.');
        }

        if ($project->trello_board_id !== null) {
            throw new RuntimeException('Project is already connected to a Trello board.');
        }

        return $this->attachBoard($project, $integration, $project->name);
    }

    /**
     * Link an *existing* Trello board (one the user already maintains on Trello)
     * to a private project. No new board is created. The board's lists, labels,
     * and cards are pulled into the project on link so tasks show up immediately.
     *
     * If the board has already been imported as an orphan project (synced but
     * never assigned a client), that orphan's tasks + settings are adopted into
     * the target project and the orphan record is deleted. If the board is
     * already linked to a client-owned project elsewhere, the link is refused.
     */
    public function linkExistingBoard(Project $project, Integration $integration, string $trelloBoardId): Project
    {
        if (! $integration->isConfigured()) {
            throw new RuntimeException('Trello integration is not configured.');
        }

        if ($project->trello_board_id !== null) {
            throw new RuntimeException('Project is already connected to a Trello board.');
        }

        $existing = Project::where('account_id', $project->account_id)
            ->where('trello_board_id', $trelloBoardId)
            ->where('id', '!=', $project->id)
            ->first();

        if ($existing !== null) {
            if ($existing->client_id !== null) {
                throw new RuntimeException('This Trello board is already linked to another project.');
            }

            // Orphan: move its tasks + settings into the target project, then
            // remove the orphan so the unique [account_id, trello_board_id]
            // constraint stays clean for the upcoming update.
            Task::where('project_id', $existing->id)->update(['project_id' => $project->id]);

            if (! empty($existing->settings)) {
                $project->settings = array_merge($project->settings ?? [], $existing->settings);
                $project->save();
            }

            $existing->delete();
        }

        $trello = new TrelloService($integration);

        $boards = collect($trello->fetchBoards());
        $board = $boards->firstWhere('id', $trelloBoardId);

        if ($board === null) {
            throw new RuntimeException('Trello board not found on this account.');
        }

        // Set trello_board_id first so syncBoard finds *this* project rather
        // than creating a fresh record. syncBoard preserves the project's
        // existing name + description on update (only Trello URL is refreshed),
        // so the user-chosen name stays put.
        $project->update([
            'trello_board_id' => $trelloBoardId,
            'trello_url' => $board['url'] ?? null,
            'settings' => array_merge($project->settings ?? [], [
                'trello_workspace' => $board['organization']['displayName'] ?? null,
            ]),
        ]);

        $trello->syncBoard($trelloBoardId, $project->account_id, $project->client_id);

        Log::info('Existing Trello board linked to project', [
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'board_id' => $trelloBoardId,
            'adopted_orphan' => $existing !== null,
        ]);

        return $project->refresh();
    }

    /**
     * Shared board-creation logic: create board on Trello, add labels + lists,
     * write the resulting IDs back onto the given project.
     */
    private function attachBoard(Project $project, Integration $integration, string $boardName): Project
    {
        $trello = new TrelloService($integration);

        $board = $trello->createBoard($boardName);

        $priorityLabels = [];
        foreach (self::PRIORITY_LABELS as $label) {
            $created = $trello->createLabel($board['id'], $label['name'], $label['color']);
            $priorityLabels[$label['color']] = $created['id'];
        }

        $lists = [];
        foreach (self::DEFAULT_LISTS as $index => $listName) {
            $list = $trello->createList($board['id'], $listName, ($index + 1) * 1000);
            $lists[] = ['id' => $list['id'], 'name' => $list['name']];
        }

        // Boards we create ourselves use canonical lane names verbatim, so the
        // mapping is just identity. Auto-detect handles edge cases consistently.
        $mapping = TrelloListMapper::autoDetectMapping($lists);

        $project->update([
            'trello_board_id' => $board['id'],
            'trello_url' => $board['url'] ?? null,
            'settings' => array_merge($project->settings ?? [], [
                'trello_lists' => $lists,
                'trello_list_mapping' => $mapping,
                'trello_priority_labels' => $priorityLabels,
            ]),
        ]);

        Log::info('Trello board attached to project', [
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'board_id' => $board['id'],
        ]);

        return $project;
    }
}

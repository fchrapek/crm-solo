<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Services\Integrations\Trello\TrelloBoardPicker;
use App\Services\Integrations\Trello\TrelloBoardSyncRunning;
use App\Services\Integrations\Trello\TrelloLinkRefused;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Services\Integrations\Trello\TrelloRequestFailed;
use App\Services\TaskSources\TaskSourceRegistry;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Trello links for `crm trello-links`, `trello-board-create`, `trello-board-link`
 * and their MCP tools: which projects hold which board, and the connect
 * dialog's two actions through the same task source provider, inside the
 * acting account. Board, list and project names on a board come from Trello.
 */
final readonly class TrelloLinks
{
    private const int CANDIDATE_LIMIT = 6;

    public function __construct(
        private CrmEntityResolver $resolver,
        private TaskSourceRegistry $taskSources,
        private TrelloBoardPicker $picker,
    ) {}

    /**
     * @param  array{client?: ?string, project?: ?string, archived?: ?bool, boards?: ?bool}  $input
     * @return array<string, mixed>
     *
     * @throws ReferenceException
     */
    public function list(int $accountId, array $input): array
    {
        $client = $this->optionalClient($accountId, $input['client'] ?? null);
        $needle = mb_trim((string) ($input['project'] ?? ''));
        $project = $needle !== '' ? $this->resolver->project($needle, $accountId, $client?->id) : null;

        $projects = Project::query()
            ->with('client:id,name')
            ->where('account_id', $accountId)
            ->whereNotNull('trello_board_id')
            ->when($client !== null, fn (Builder $q) => $q->where('client_id', $client?->id))
            ->when($project !== null, fn (Builder $q) => $q->whereKey($project?->id))
            ->when($project === null && ! ($input['archived'] ?? false), fn (Builder $q) => $q->whereNull('archived_at'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $integration = $this->integration($accountId);
        $sync = $this->syncFacts($accountId, $integration);
        $wantsBoards = (bool) ($input['boards'] ?? false);
        $boards = null;
        $boardsError = null;
        if ($integration !== null && ($projects->isNotEmpty() || $wantsBoards)) {
            try {
                $boards = collect($this->picker->boards($integration))->filter(fn (array $board): bool => $board['id'] !== null)->values();
            } catch (RuntimeException $e) {
                $boardsError = $this->failureText($e);
            }
        }
        $byId = $boards?->keyBy('id');

        return [
            'connected' => $integration !== null,
            'synced_at' => $sync['synced_at'],
            'sync_error' => $sync['error'],
            'boards_error' => $boardsError,
            'total' => $projects->count(),
            'links' => $projects->map(fn (Project $linked): array => $this->item($linked, $byId?->get($linked->trello_board_id), $byId !== null, $sync))->values()->all(),
            ...($wantsBoards ? ['boards' => $boards?->all() ?? []] : []),
            'untrusted' => $wantsBoards ? ['sync_error', 'boards'] : ['sync_error'],
        ];
    }

    /**
     * A new board made on Trello for the project, as the connect dialog's "Create new board" does.
     *
     * @param  array{project?: ?string, client?: ?string}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function createBoard(int $accountId, array $input): array
    {
        [$project, $integration] = $this->target($accountId, $input);

        return $this->locked($accountId, function () use ($project, $integration, $accountId): array {
            $this->stillUnlinked($project);

            try {
                $linked = $this->taskSources->get('trello')->connectNewContainer($project, $integration);
            } catch (TrelloLinkRefused $e) {
                throw $this->refusal($e);
            } catch (TrelloRequestFailed $e) {
                throw new AgentRefusal(AgentRefusal::TRELLO_FAILED, 'No board was linked: '.$e->summary().' A board may have been made on Trello before the failure; look there before trying again.', ['status' => $e->status]);
            } catch (RuntimeException $e) {
                throw $this->trelloFailed($e, 'No board was linked');
            }

            $linked->refresh()->load('client:id,name');
            $board = ['id' => $linked->trello_board_id, 'name' => (string) $linked->name, 'url' => $linked->trello_url, 'workspace' => null];

            return ['link' => $this->item($linked, $board, true, $this->syncFacts($accountId, $integration))];
        });
    }

    /**
     * An existing board linked to the project and its cards pulled, as the
     * connect dialog's "Link existing board" does. A board held by a
     * client-less project is taken over from it, tasks included.
     *
     * @param  array{project?: ?string, board?: ?string, client?: ?string}  $input
     * @return array<string, mixed>
     *
     * @throws AgentRefusal|ReferenceException
     */
    public function linkBoard(int $accountId, array $input): array
    {
        $needle = mb_trim((string) ($input['board'] ?? ''));
        if ($needle === '') {
            throw AgentRefusal::invalid('Pass the board: its id, short link, URL or part of its name.', ['field' => 'board']);
        }

        [$project, $integration] = $this->target($accountId, $input);

        try {
            $boards = $this->picker->boards($integration);
        } catch (RuntimeException $e) {
            throw $this->trelloFailed($e, 'The boards could not be read');
        }
        $board = $this->board($boards, $needle);

        return $this->locked($accountId, function () use ($project, $integration, $accountId, $board): array {
            $this->stillUnlinked($project);
            $orphan = Project::query()
                ->where('account_id', $accountId)
                ->where('trello_board_id', $board['id'])
                ->whereNull('client_id')
                ->whereKeyNot($project->id)
                ->value('id');

            $warnings = [];
            try {
                $linked = $this->taskSources->get('trello')->linkExistingContainer($project, $integration, (string) $board['id']);
                $synced = true;
            } catch (TrelloLinkRefused $e) {
                throw $this->refusal($e, (string) $board['id']);
            } catch (RuntimeException $e) {
                $linked = $project->refresh();
                if ($linked->trello_board_id !== $board['id']) {
                    throw $this->trelloFailed($e, 'No board was linked');
                }
                $synced = false;
                $why = $e instanceof TrelloBoardSyncRunning ? 'a sync of this board is already running.' : $this->failureText($e);
                $warnings[] = "Linked, but the first sync did not finish: {$why} The scheduled sync pulls the cards within minutes.";
            }

            $linked->refresh()->load('client:id,name');

            return [
                'link' => $this->item($linked, $board, true, $this->syncFacts($accountId, $integration)),
                'synced' => $synced,
                'card_tasks' => $linked->tasks()->whereNotNull('trello_card_id')->count(),
                'adopted_project_id' => $orphan !== null ? (int) $orphan : null,
                'warnings' => $warnings,
            ];
        });
    }

    /** The short link in a board URL (trello.com/b/{short}/...), or the needle itself when it is shaped like one. */
    private static function shortLink(string $value): ?string
    {
        if (preg_match('~trello\.com/b/([A-Za-z0-9]+)~i', $value, $match) === 1) {
            return $match[1];
        }

        return preg_match('/^[A-Za-z0-9]{8}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $board  the board as Trello lists it, null when it was not read or not seen
     * @param  array{connected: bool, synced_at: string|null, error: string|null}  $sync
     * @return array<string, mixed>
     */
    private function item(Project $project, ?array $board, bool $looked, array $sync): array
    {
        $settings = $project->settings ?? [];
        $mapping = (array) ($settings['trello_list_mapping'] ?? []);
        $lists = collect((array) ($settings['trello_lists'] ?? []))
            ->filter(fn (mixed $list): bool => is_array($list) && isset($list['id']))
            ->map(fn (array $list): array => [
                'id' => (string) $list['id'],
                'name' => (string) ($list['name'] ?? ''),
                'lane' => isset($mapping[$list['id']]) ? (string) $mapping[$list['id']] : null,
            ])
            ->values();

        return [
            'project_id' => (int) $project->id,
            'project' => (string) $project->name,
            'client_id' => $project->client_id !== null ? (int) $project->client_id : null,
            'client' => $project->client?->name,
            'archived' => $project->isArchived(),
            'board' => [
                'id' => (string) $project->trello_board_id,
                'name' => $board['name'] ?? null,
                'url' => $project->trello_url ?? ($board['url'] ?? null),
                'workspace' => $board['workspace'] ?? ($settings['trello_workspace'] ?? null),
                'visible' => $looked ? $board !== null : null,
            ],
            'lists' => $lists->all(),
            'mapping' => [
                'lists' => $lists->count(),
                'mapped' => $lists->whereNotNull('lane')->count(),
                'done_lists' => $lists->where('lane', TrelloListMapper::LANE_DONE)->count(),
                'custom_lanes' => array_values((array) ($settings['custom_lanes'] ?? [])),
            ],
            'sync' => [
                'state' => match (true) {
                    $project->isArchived() => 'paused',
                    ! $sync['connected'] => 'off',
                    $sync['error'] !== null => 'failed',
                    $sync['synced_at'] === null => 'never',
                    default => 'ok',
                },
                'synced_at' => $sync['synced_at'],
            ],
            'untrusted' => ['project', 'board', 'lists'],
        ];
    }

    /**
     * The project to link and the connection to link it through, or the refusal the dialog would show.
     *
     * @param  array{project?: ?string, client?: ?string}  $input
     * @return array{0: Project, 1: Integration}
     */
    private function target(int $accountId, array $input): array
    {
        $needle = mb_trim((string) ($input['project'] ?? ''));
        if ($needle === '') {
            throw AgentRefusal::invalid('Pass the project (id or name fragment) to link a board to.', ['field' => 'project']);
        }

        $client = $this->optionalClient($accountId, $input['client'] ?? null);
        $project = $this->resolver->project($needle, $accountId, $client?->id);
        $this->refuseLinked($project);
        if ($project->isArchived()) {
            throw AgentRefusal::invalid("Project #{$project->id} is archived and the sync skips archived projects; restore it before linking a board.", ['project_id' => (int) $project->id]);
        }
        if (config('app.demo')) {
            throw AgentRefusal::featureOff('Trello');
        }

        $integration = $this->integration($accountId)
            ?? throw new AgentRefusal(AgentRefusal::TRELLO_NOT_CONNECTED, 'Trello is not connected for this account; the owner connects it in '.__('Settings').', '.__('Integrations').'.');

        return [$project, $integration];
    }

    /**
     * A board by its id, short link or URL, else by name: an exact name first, then a fragment.
     *
     * @param  list<array<string, mixed>>  $boards  TrelloBoardPicker::boards()
     * @return array<string, mixed>
     */
    private function board(array $boards, string $needle): array
    {
        $boards = collect($boards)->filter(fn (array $board): bool => $board['id'] !== null)->values();
        $short = self::shortLink($needle);
        $exact = $boards->filter(fn (array $board): bool => $board['id'] === $needle
            || ($short !== null && self::shortLink((string) ($board['url'] ?? '')) === $short));
        if ($exact->count() === 1) {
            return $exact->first();
        }
        if (str_contains($needle, '/')) {
            throw new ReferenceNotFoundException('trello board', $needle, 'No board the Trello connection can see has that link.');
        }

        $folded = mb_strtolower($needle);
        $named = $boards->filter(fn (array $board): bool => mb_strtolower((string) $board['name']) === $folded);
        $matches = $named->count() === 1
            ? $named
            : $boards->filter(fn (array $board): bool => str_contains(mb_strtolower((string) $board['name']), $folded))->values();

        if ($matches->isEmpty()) {
            throw new ReferenceNotFoundException('trello board', $needle, 'List the boards with trello-links --boards.');
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }

        throw new AmbiguousReferenceException(
            'trello board',
            $needle,
            $matches->take(self::CANDIDATE_LIMIT)->map(fn (array $board): array => [
                'id' => (string) $board['id'],
                'name' => (string) $board['name'],
                'context' => $this->boardContext($board),
            ])->values()->all(),
            $matches->count(),
            'the board id',
        );
    }

    /**
     * @param  array<string, mixed>  $board
     */
    private function boardContext(array $board): ?string
    {
        $status = match ($board['linked_status'] ?? null) {
            'linked' => "linked to project #{$board['linked_project_id']}".(($board['linked_client_name'] ?? null) !== null ? " of {$board['linked_client_name']}" : ''),
            'orphan' => "held by project #{$board['linked_project_id']} without a client",
            default => null,
        };

        return collect([$board['workspace'] ?? null, $status])->filter()->implode(', ') ?: null;
    }

    private function optionalClient(int $accountId, ?string $needle): ?Client
    {
        $needle = mb_trim((string) $needle);

        return $needle !== '' ? $this->resolver->client($needle, $accountId) : null;
    }

    private function integration(int $accountId): ?Integration
    {
        return config('app.demo') ? null : Integration::configuredTrello($accountId);
    }

    /**
     * The account's sync, as the project page shows it: one Trello sync runs per account.
     *
     * @return array{connected: bool, synced_at: string|null, error: string|null}
     */
    private function syncFacts(int $accountId, ?Integration $integration): array
    {
        $record = $integration ?? Integration::query()->where('account_id', $accountId)->where('provider', 'trello')->first();

        return [
            'connected' => $integration !== null,
            'synced_at' => $record?->last_synced_at?->toIso8601String(),
            'error' => $record?->last_sync_error,
        ];
    }

    private function refuseLinked(Project $project): void
    {
        if ($project->trello_board_id !== null) {
            throw new AgentRefusal(
                AgentRefusal::ALREADY_LINKED,
                "Project #{$project->id} is already linked to Trello board {$project->trello_board_id}.",
                ['project_id' => (int) $project->id, 'board_id' => (string) $project->trello_board_id, 'board_url' => $project->trello_url, 'untrusted' => ['board_url']],
            );
        }
    }

    /** Read again under the lock: a link made since the first read is refused, not doubled. */
    private function stillUnlinked(Project $project): void
    {
        $project->refresh();
        $this->refuseLinked($project);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(int $accountId, Closure $callback): mixed
    {
        try {
            return Cache::lock("agent:trello-link:{$accountId}", 600)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new AgentRefusal(AgentRefusal::REFUSED, 'Another Trello board is being linked on this account; try again in a moment.');
        }
    }

    private function refusal(TrelloLinkRefused $e, string $boardId = ''): AgentRefusal|ReferenceException
    {
        $holder = $e->holder;

        return match ($e->reason) {
            TrelloLinkRefused::NOT_CONFIGURED => new AgentRefusal(AgentRefusal::TRELLO_NOT_CONNECTED, $e->getMessage()),
            TrelloLinkRefused::ALREADY_LINKED => new AgentRefusal(AgentRefusal::ALREADY_LINKED, $e->getMessage()),
            TrelloLinkRefused::LINKED_ELSEWHERE => new AgentRefusal(AgentRefusal::BOARD_LINKED_ELSEWHERE, $e->getMessage().' Unlinking it is the owner\'s action on that project in the app.', $holder !== null ? [
                'candidates' => [['id' => (int) $holder->id, 'name' => (string) $holder->name, 'context' => $holder->client?->name]],
                'untrusted' => ['candidates'],
            ] : []),
            TrelloLinkRefused::BOARD_NOT_FOUND => new ReferenceNotFoundException('trello board', $boardId, 'The board is no longer visible to the Trello connection.'),
            default => new AgentRefusal(AgentRefusal::REFUSED, $e->getMessage()),
        };
    }

    private function trelloFailed(RuntimeException $e, string $what): AgentRefusal
    {
        return $e instanceof TrelloRequestFailed
            ? new AgentRefusal(AgentRefusal::TRELLO_FAILED, "{$what}: {$e->summary()}", ['status' => $e->status])
            : new AgentRefusal(AgentRefusal::REFUSED, "{$what}: {$e->getMessage()}");
    }

    /** A Trello answer is never quoted: it is summarised as its status. */
    private function failureText(RuntimeException $e): string
    {
        return $e instanceof TrelloRequestFailed ? $e->summary() : $e->getMessage();
    }
}

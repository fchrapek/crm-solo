<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use Exception;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class TrelloService
{
    /** Maps Trello label colors to priority levels. */
    public const PRIORITY_COLORS = [
        'red' => 'high',
        'yellow' => 'medium',
        'green' => 'low',
    ];

    private const BASE_URL = 'https://api.trello.com/1';

    /** Longest a board sync may hold its lock; a crashed run frees the board after this. */
    private const BOARD_LOCK_SECONDS = 600;

    /** The most cards Trello returns in one nested card list; a response this long may be truncated. */
    private const CARD_LIST_CAP = 1000;

    /** A board sync stops taking on cards after this long, well inside its lock lease. */
    private const SYNC_DEADLINE_SECONDS = 480;

    /** Move lookups per board sync; the rest stay pending and are asked on the next run. */
    private const MOVE_LOOKUPS_PER_RUN = 10;

    /** A move lookup is one small request: it gets a short leash. */
    private const MOVE_LOOKUP_TIMEOUT = 10;

    /** Newest comments fetched with a card's details; Trello allows up to 1000. */
    private const COMMENT_LIMIT = 100;

    /** Seconds one attachment download may take; files are capped well below what this allows. */
    private const DOWNLOAD_TIMEOUT = 120;

    private int $moveLookupsLeft = 0;

    private PendingRequest $http;

    /** @var list<string> */
    private array $secrets;

    public function __construct(
        private readonly Integration $integration
    ) {
        $this->integration->assertSecretsReadable();

        $settings = $this->integration->settings ?? [];
        $apiKey = $settings['trello_api_key'] ?? config('services.trello.api_key');
        $apiToken = $this->integration->api_key;

        if (! $apiKey || ! $apiToken) {
            throw new RuntimeException('Trello API key or token is not configured.');
        }

        $this->secrets = [(string) $apiKey, (string) $apiToken];

        // Header, not query string: a URL ends up in exception messages, a header does not.
        $this->http = Http::baseUrl($this->baseUrl())
            ->withHeaders([
                'Authorization' => sprintf('OAuth oauth_consumer_key="%s", oauth_token="%s"', $apiKey, $apiToken),
            ])
            ->timeout(30);
    }

    /**
     * The lock that makes everything rewriting a board's cards (a sync, a
     * list-mapping change) take turns. It needs a cache store shared by the
     * web server, the scheduler and the queue workers (Redis in the documented
     * setup), and it expires so a crashed holder frees the board.
     */
    public static function boardLock(string $boardId): Lock
    {
        return Cache::lock('trello-sync:board:'.$boardId, self::BOARD_LOCK_SECONDS);
    }

    public function testConnection(): bool
    {
        $response = $this->send('GET', '/members/me');

        return $response->ok();
    }

    public function fetchBoards(): array
    {
        $response = $this->send('GET', '/members/me/boards', [
            'fields' => 'name,desc,url,closed,idOrganization',
            'filter' => 'open',
            'organization' => 'true',
            'organization_fields' => 'displayName',
        ]);

        if ($response->failed()) {
            throw $this->failure('Failed to fetch Trello boards', $response);
        }

        return $response->json();
    }

    public function fetchLists(string $boardId): array
    {
        $response = $this->send('GET', "/boards/{$boardId}/lists", [
            'fields' => 'name,pos,closed',
            'filter' => 'open',
        ]);

        if ($response->failed()) {
            throw $this->failure('Failed to fetch Trello lists', $response);
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
        $response = $this->send('GET', "/boards/{$boardId}/cards", [
            'fields' => 'name,desc,idList,pos,due,dueComplete,url,labels,closed,dateLastActivity',
            'filter' => 'all',
        ]);

        if ($response->failed()) {
            throw $this->failure('Failed to fetch Trello cards', $response);
        }

        return $response->json();
    }

    /**
     * One card's checklists, comment history and attachment list in a single
     * request, plus its last activity so the caller knows which version it
     * holds. Read-only; the sync never calls it.
     *
     * @return array<string, mixed>
     */
    public function fetchCardDetails(string $cardId): array
    {
        $response = $this->send('GET', '/cards/'.$this->pathSegment($cardId), [
            'fields' => 'dateLastActivity',
            'checklists' => 'all',
            'checklist_fields' => 'name,pos',
            'attachments' => 'true',
            'attachment_fields' => 'id,name,url,bytes,mimeType,isUpload,date',
            'actions' => 'commentCard',
            'actions_limit' => self::COMMENT_LIMIT,
            'action_fields' => 'data,date,type',
            'action_memberCreator_fields' => 'fullName,username',
        ]);

        if ($response->failed()) {
            throw $this->failure('Failed to fetch Trello card', $response);
        }

        $card = $response->json();

        return is_array($card) ? $card : [];
    }

    /**
     * Streams a file Trello hosts for the card straight to disk. The request
     * is built from the card and attachment ids, never from a URL the card
     * holds, and it stops the transfer as soon as the body passes $maxBytes.
     *
     * @throws TrelloAttachmentTooLarge when the file is larger than $maxBytes
     * @throws TrelloRequestFailed when Trello refuses or the transfer fails
     */
    public function downloadAttachment(string $cardId, string $attachmentId, string $fileName, string $to, int $maxBytes, int $timeoutSeconds = self::DOWNLOAD_TIMEOUT): void
    {
        $state = new CappedFileSinkState;
        $sink = CappedFileSink::open($to, $maxBytes, $state);
        // Each response in a redirect chain starts the file over, and a declared size over the cap stops at the headers.
        $onHeaders = function (ResponseInterface $response) use ($sink, $state, $maxBytes): void {
            ftruncate($sink, 0);
            rewind($sink);
            $state->written = 0;
            $length = $response->getHeaderLine('Content-Length');
            if ($response->getStatusCode() < 300 && ctype_digit($length) && (int) $length > $maxBytes) {
                $state->refuse();

                throw new RuntimeException('Attachment is larger than the limit.');
            }
        };

        try {
            $response = $this->send(
                'GET',
                '/cards/'.$this->pathSegment($cardId).'/attachments/'.$this->pathSegment($attachmentId).'/download/'.rawurlencode($fileName),
                [],
                [
                    'sink' => $sink,
                    'on_headers' => $onHeaders,
                    'timeout' => min($timeoutSeconds, self::DOWNLOAD_TIMEOUT),
                    // Trello may redirect to its file store: follow at most three hops, over the API's own
                    // scheme only, with no Referer; a hop to another origin drops the Authorization header.
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => [(string) parse_url($this->baseUrl(), PHP_URL_SCHEME)],
                    ],
                ],
            );
        } catch (TrelloRequestFailed $e) {
            throw $state->refused ? new TrelloAttachmentTooLarge : $e;
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        if ($state->refused) {
            throw new TrelloAttachmentTooLarge;
        }

        if ($response->failed()) {
            throw $this->failure('Failed to download Trello attachment', $response);
        }
    }

    public function createLabel(string $boardId, string $name, string $color): array
    {
        $response = $this->send('POST', '/labels', [
            'idBoard' => $boardId,
            'name' => $name,
            'color' => $color,
        ]);

        if ($response->failed()) {
            throw $this->failure('Failed to create Trello label', $response);
        }

        return $response->json();
    }

    public function fetchLabels(string $boardId): array
    {
        $response = $this->send('GET', "/boards/{$boardId}/labels");

        if ($response->failed()) {
            throw $this->failure('Failed to fetch Trello labels', $response);
        }

        return $response->json();
    }

    public function createBoard(string $name): array
    {
        $response = $this->send('POST', '/boards', [
            'name' => $name,
            'defaultLists' => 'false',
        ]);

        if ($response->failed()) {
            throw $this->failure('Failed to create Trello board', $response);
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

        $response = $this->send('POST', '/lists', $params);

        if ($response->failed()) {
            throw $this->failure('Failed to create Trello list', $response);
        }

        return $response->json();
    }

    /**
     * Sync a specific board's cards into the local database.
     */
    /**
     * One sync per board at a time: the scheduler, the Sync button and the
     * queued job can overlap. The lock expires on its own, so a crashed run
     * cannot hold the board forever.
     */
    public function syncBoard(string $boardId, int $accountId, ?int $clientId = null): array
    {
        $lock = self::boardLock($boardId);
        if (! $lock->get()) {
            throw new TrelloBoardSyncRunning("A sync of Trello board {$boardId} is already running.");
        }

        try {
            return $this->syncBoardLocked($boardId, $accountId, $clientId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Sync only adopted boards: the token sees every board its owner was ever
     * added to, most of which belong to no client.
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

    /** The API root; configurable so the transfer tests can point it at a local server. */
    private function baseUrl(): string
    {
        return (string) config('services.trello.base_url', self::BASE_URL);
    }

    /**
     * When the card last changed list, from its own action history. An empty
     * history is an answer (no move); a failed request, or one over this run's
     * lookup budget, leaves the question open, and the caller keeps the
     * owner's finish and asks again next sync.
     */
    private function lastMove(string $cardId): CardMove
    {
        if ($this->moveLookupsLeft <= 0) {
            return CardMove::unknown();
        }
        $this->moveLookupsLeft--;

        try {
            $response = $this->send(
                'GET',
                '/cards/'.$this->pathSegment($cardId).'/actions',
                ['filter' => 'updateCard:idList'],
                ['timeout' => self::MOVE_LOOKUP_TIMEOUT, 'connect_timeout' => self::MOVE_LOOKUP_TIMEOUT],
            );
        } catch (TrelloRequestFailed $e) {
            Log::warning('Trello card move lookup failed', ['card_id' => $cardId, 'error' => $e->getMessage()]);

            return CardMove::unknown();
        }

        if ($response->failed() || ! is_array($response->json())) {
            Log::warning('Trello card move lookup failed', ['card_id' => $cardId, 'status' => $response->status()]);

            return CardMove::unknown();
        }

        $latest = collect($response->json())
            ->pluck('date')
            ->filter(fn ($date) => is_string($date))
            ->map(fn (string $date) => Carbon::parse($date)->setTimezone(config('app.timezone')))
            ->sort()
            ->last();

        return CardMove::answered($latest instanceof Carbon ? $latest : null);
    }

    private function syncBoardLocked(string $boardId, int $accountId, ?int $clientId): array
    {
        $deadline = now()->addSeconds(self::SYNC_DEADLINE_SECONDS);
        $this->moveLookupsLeft = self::MOVE_LOOKUPS_PER_RUN;
        $stats = ['created' => 0, 'updated' => 0, 'errors' => 0];

        $boardResponse = $this->send('GET', "/boards/{$boardId}", [
            'fields' => 'name,desc,url',
        ]);

        if ($boardResponse->failed()) {
            throw $this->failure('Failed to fetch board', $boardResponse);
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
        $complete = $this->isCompleteCardList($cards);

        foreach ($cards as $card) {
            // Out of time: the remaining cards wait for the next run, and an unfinished pass archives nothing.
            if (now()->gte($deadline)) {
                $complete = false;
                Log::warning('Trello board sync stopped at its deadline', ['board_id' => $boardId]);

                break;
            }

            try {
                $outcome = $this->syncCard($project, $card, $listToCanonical);
                if ($outcome === 'created' || $outcome === 'updated') {
                    $stats[$outcome]++;
                }
            } catch (Exception $e) {
                Log::warning('Failed to sync Trello card', [
                    'card_id' => is_array($card) ? ($card['id'] ?? null) : null,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        // A card the board no longer returns was deleted (or moved off it): archive its task, never
        // delete it, since time and history hang off it. It comes back if the card does.
        if ($complete) {
            $vanished = Task::query()
                ->where('project_id', $project->id)
                ->whereNotNull('trello_card_id')
                ->whereNotIn('trello_card_id', collect($cards)->pluck('id')->all())
                ->whereNull('archived_at')
                ->update(['archived_at' => now()]);
            if ($vanished > 0) {
                Log::info('Archived tasks whose Trello cards are gone', ['board_id' => $boardId, 'count' => $vanished]);
            }
        }

        $this->integration->update(['last_synced_at' => now()]);

        return $stats;
    }

    /**
     * Whether a cards response can be trusted to hold every card on the
     * board: a list of cards that each carry an id, short of the 1000 that
     * Trello caps a nested card list at. A response at the cap may be cut,
     * so it never archives anything.
     *
     * @param  mixed  $cards
     */
    private function isCompleteCardList($cards): bool
    {
        return is_array($cards)
            && array_is_list($cards)
            && count($cards) < self::CARD_LIST_CAP
            && collect($cards)->every(fn ($card) => is_array($card) && is_string($card['id'] ?? null) && $card['id'] !== '');
    }

    /**
     * Writes one card under its task's row lock, in one transaction: the
     * card's fields and the owner-layer changes that follow from them land
     * in one statement, decided on the row as it is now. A fetch older than
     * the version the row already holds (its trello_activity_at) is ignored.
     *
     * @return 'created'|'updated'|'stale'
     */
    private function syncCard(Project $project, array $card, array $listToCanonical): string
    {
        $lane = $listToCanonical[$card['idList']] ?? TrelloListMapper::LANE_BACKLOG;
        $listId = $card['idList'] ?? null;
        $closed = (bool) ($card['closed'] ?? false);
        $dueComplete = (bool) ($card['dueComplete'] ?? false);
        $isCompleted = CardFinishReconciler::isCompleted($lane, $dueComplete);
        $activity = isset($card['dateLastActivity'])
            ? Carbon::parse($card['dateLastActivity'])->setTimezone(config('app.timezone'))
            : null;
        $cardLabels = collect($card['labels'] ?? []);
        $projectLabels = $this->extractProjectLabels($cardLabels);

        // Only a finished card that moved to an active list could be reopened: only then ask when it moved.
        // Read without the lock, so no network call happens while the row is held.
        $seen = Task::query()->where('project_id', $project->id)->where('trello_card_id', $card['id'])->first();
        $move = $seen !== null && CardFinishReconciler::needsMoveCheck($seen, $listId, $lane, $isCompleted)
            ? $this->lastMove($card['id'])
            : CardMove::unknown();

        $fields = [
            'trello_list_id' => $listId,
            'trello_due_complete' => $dueComplete,
            'trello_activity_at' => $activity,
            'name' => $card['name'],
            'description' => $card['desc'] ?? null,
            'list_name' => $lane,
            'position' => min((int) ($card['pos'] ?? 0), 2147483647),
            'due_date' => $card['due'] ?? null,
            'labels' => $projectLabels ?: null,
            'trello_url' => $card['url'] ?? null,
            'is_completed' => $isCompleted,
            'priority' => $this->extractPriorityFromLabels($cardLabels),
        ];

        return DB::transaction(function () use ($project, $card, $fields, $listId, $lane, $isCompleted, $activity, $closed, $move): string {
            $current = Task::query()
                ->where('project_id', $project->id)
                ->where('trello_card_id', $card['id'])
                ->lockForUpdate()
                ->first();

            // A card moved here from another synced board of the SAME client keeps its task (time,
            // history, picks). Across clients the old task stays with its client, its time and its
            // reports, and is archived there as a card that left its board; this board gets a new task.
            if ($current === null && $project->client_id !== null) {
                $current = Task::query()
                    ->where('trello_card_id', $card['id'])
                    ->where('project_id', '!=', $project->id)
                    ->whereHas('project', fn ($q) => $q
                        ->where('account_id', $project->account_id)
                        ->where('client_id', $project->client_id)
                        ->whereNotNull('trello_board_id'))
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
            }

            if ($current === null) {
                Task::create([
                    'project_id' => $project->id,
                    'trello_card_id' => $card['id'],
                    ...$fields,
                    'archived_at' => $closed ? now() : null,
                ]);

                return 'created';
            }

            if ($activity !== null && $current->trello_activity_at !== null && $activity->lt($current->trello_activity_at)) {
                return 'stale';
            }

            $owner = CardFinishReconciler::ownerChanges($current, $listId, $lane, $isCompleted, $move);

            // Archive mirrors Trello's closed flag; a still-closed card keeps its first archive date.
            $current->fill([
                'project_id' => $project->id,
                ...$fields,
                'archived_at' => $closed ? ($current->archived_at ?? now()) : null,
                ...$owner,
            ])->save();

            return 'updated';
        });
    }

    /**
     * The one way out of this client for an error text: escaped and encoded
     * forms are decoded first (JSON values, \uXXXX, %XX, HTML entities), then
     * the key, the token and any key= or token= value are cut, then the text
     * is shortened. Text that still holds a run of either secret is dropped
     * (null) and the caller reports without it. The original exception is
     * never chained, since a report would print its message too.
     */
    private function sanitize(string $text): ?string
    {
        $json = json_decode($text, true);
        if (is_array($json) || is_string($json)) {
            $text = (string) json_encode($this->redactDeep($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $text = $this->redact($this->decoded($text));

        foreach ($this->secrets as $secret) {
            if ($this->holdsPartOf($text, $secret)) {
                return null;
            }
        }

        return mb_substr($text, 0, 500);
    }

    private function redact(string $text): string
    {
        $secrets = array_values(array_filter($this->secrets, fn (string $secret): bool => $secret !== ''));
        $text = str_ireplace($secrets, '[redacted]', $text);

        return (string) preg_replace('/\b(key|token)=[^&\s"\'<>]+/i', '$1=[redacted]', $text);
    }

    private function redactDeep(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->redactDeep($item), $value);
        }

        return is_string($value) ? $this->redact($this->decoded($value)) : $value;
    }

    private function decoded(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8') ?: '',
            $text,
        );

        return html_entity_decode(rawurldecode($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Any eight-character run of the secret, so a cut-off or partly redacted
     * copy is caught too.
     */
    private function holdsPartOf(string $text, string $secret): bool
    {
        $length = mb_strlen($secret);
        if ($length === 0) {
            return false;
        }

        $window = min(8, $length);
        for ($i = 0; $i + $window <= $length; $i++) {
            if (mb_stripos($text, mb_substr($secret, $i, $window)) !== false) {
                return true;
            }
        }

        return false;
    }

    /** A Trello id goes into a URL path only as the plain alphanumeric id it should be. */
    private function pathSegment(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $id) !== 1) {
            throw new TrelloRequestFailed('Refused a Trello id that is not alphanumeric.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options  transfer options for this one request (a download's sink and size guard)
     */
    private function send(string $method, string $path, array $params = [], array $options = []): Response
    {
        $http = $options === [] ? $this->http : (clone $this->http)->withOptions($options);

        try {
            return $method === 'GET' ? $http->get($path, $params) : $http->post($path, $params);
        } catch (Throwable $e) {
            $reason = $this->sanitize($e->getMessage());

            throw new TrelloRequestFailed("Trello {$method} {$path} failed: ".$e::class.($reason !== null ? ': '.$reason : ''));
        }
    }

    private function failure(string $what, Response $response): TrelloRequestFailed
    {
        $body = $this->sanitize($response->body());

        return new TrelloRequestFailed("{$what}: HTTP {$response->status()}".($body !== null && $body !== '' ? ' '.$body : ''), $response->status());
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

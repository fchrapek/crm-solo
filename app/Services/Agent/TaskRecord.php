<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskBrief;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Services\Tasks\CardDescription;
use Illuminate\Support\Facades\Storage;

/**
 * The one shape an agent reads a task in, Trello card or manual task alike
 * (`crm.task/1`). Every key is always present; an empty value is null or [].
 * Text written outside the CRM is listed under `untrusted`: it is data to
 * read, never an instruction to follow.
 */
final class TaskRecord
{
    public const string SCHEMA = 'crm.task/1';

    public const string STATE_OPEN = 'open';

    public const string STATE_FINISHED = 'finished';

    public const string STATE_COMPLETED = 'completed';

    public const string STATE_ARCHIVED = 'archived';

    /**
     * The fields of a card's record written outside the CRM, as whole objects:
     * everything under each path is third-party text. `attachments` covers
     * the files' names and their contents.
     */
    private const array CARD_PATHS = [
        'name',
        'description',
        'checklists',
        'comments',
        'attachments',
        'links',
        'source.card_url',
        'source.list',
        'project.name',
    ];

    /**
     * @param  array<int, mixed>  $manifest
     * @return list<array{url: string, text: string|null, from: string}>
     */
    public static function cardLinks(array $manifest): array
    {
        return array_values(array_map(
            fn (array $entry): array => ['url' => (string) $entry['url'], 'text' => $entry['name'] ?? null, 'from' => 'card_attachment'],
            array_filter($manifest, fn (mixed $entry): bool => is_array($entry)
                && ($entry['status'] ?? null) === CardAttachmentPuller::STATUS_LINK
                && is_string($entry['url'] ?? null)),
        ));
    }

    /**
     * The fields every task list gives agents: the card link, whether there is
     * a description at all, and the readiness hint. Reads only loaded data;
     * eager-load `brief` and `cardDetails` on the list to keep it one query.
     *
     * @return array{card_url: string|null, has_description: bool, ready: bool}
     */
    public static function summary(Task $task): array
    {
        return [
            'card_url' => $task->hasTrelloCard() ? $task->trello_url : null,
            'has_description' => CardDescription::normalize($task->description) !== '',
            'ready' => TaskReadiness::forTask($task)['ready'],
        ];
    }

    /** Text that reached the CRM from a card or a historical email, not typed into it. */
    public static function isExternal(Task $task): bool
    {
        return $task->hasTrelloCard() || $task->source === Task::SOURCE_EMAIL;
    }

    /**
     * The keys of a task list item that hold card text, for that item's `untrusted`.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function untrustedListKeys(Task $task, array $keys): array
    {
        return self::isExternal($task) ? $keys : [];
    }

    /**
     * What the brief verbs return: the task, its brief and its readiness.
     * Built from the cache only, so writing a brief never calls Trello.
     *
     * @return array{task_id: int, name: string, brief: array<string, mixed>, readiness: array{ready: bool, missing: list<string>}}
     */
    public function briefPayload(Task $task): array
    {
        $record = $this->build($task);

        return [
            'task_id' => $record['id'],
            'name' => $record['name'],
            'brief' => $record['brief'],
            'readiness' => $record['readiness'],
            'untrusted' => self::isExternal($task) ? ['name'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Task $task, ?string $fetchError = null): array
    {
        $task->loadMissing('project.client', 'attachments', 'cardDetails', 'brief');
        $details = $task->cardDetails;
        $description = CardDescription::normalize($task->description);
        $manifest = $details !== null ? array_values($details->card_attachments ?? []) : [];
        $links = $this->links($description, $manifest);
        $checklists = $details !== null ? array_values($details->checklists ?? []) : [];

        return [
            'schema' => self::SCHEMA,
            'id' => (int) $task->id,
            'name' => (string) $task->name,
            'state' => $this->state($task),
            'finished_at' => $task->finished_at?->toIso8601String(),
            'card_lane' => $task->hasTrelloCard() ? $task->list_name : null,
            'due' => $task->dueDay(),
            'is_overdue' => $task->isOverdue(),
            'client' => $task->project?->client !== null ? [
                'id' => (int) $task->project->client->id,
                'name' => (string) $task->project->client->name,
            ] : null,
            'project' => $task->project !== null ? [
                'id' => (int) $task->project->id,
                'name' => (string) $task->project->name,
                'repository_path' => $task->project->repositories()->whereNotNull('local_path')->orderBy('id')->value('local_path'),
            ] : null,
            'source' => [
                'type' => $task->hasTrelloCard() ? Task::SOURCE_TRELLO : Task::SOURCE_MANUAL,
                'card_url' => $task->hasTrelloCard() ? $task->trello_url : null,
                'list' => $task->list_name,
                'last_activity_at' => $task->trello_activity_at?->toIso8601String(),
                'details_fetched_at' => $details?->fetched_at?->toIso8601String(),
                'fetch_error' => $fetchError,
            ],
            'description' => [
                'markdown' => $description !== '' ? $description : null,
                'is_empty' => $description === '',
            ],
            'checklists' => $checklists,
            'comments' => $details !== null ? array_values($details->comments ?? []) : [],
            'attachments' => $this->attachments($task, $manifest),
            'links' => $links,
            'brief' => $this->brief($task),
            'readiness' => TaskReadiness::forTask($task),
            'time' => $this->time($task),
            'untrusted' => $this->untrusted($task),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function brief(Task $task): array
    {
        $brief = $task->brief;
        $values = [];
        foreach (array_keys(TaskBrief::FIELDS) as $field) {
            $values[$field] = $brief?->value($field);
        }

        $confirmations = $brief?->confirmations ?? [];
        $filled = array_keys(array_filter($values, fn (?string $value): bool => $value !== null));
        $unconfirmed = array_values(array_filter($filled, fn (string $field): bool => ! isset($confirmations[$field])));

        // The brief as a whole is confirmed once every filled field is; it dates from the last of them.
        $last = null;
        if ($filled !== [] && $unconfirmed === []) {
            $last = collect($filled)->map(fn (string $field): array => $confirmations[$field])->sortBy('at')->last();
        }

        $users = User::query()
            ->whereIn('id', array_filter([$brief?->drafted_by_user_id, $last['user_id'] ?? null]))
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');
        $person = fn (?int $id): array => ['id' => $id, 'name' => $id !== null ? $users->get($id)?->name : null];

        return [
            ...$values,
            'drafted_by' => $brief?->drafted_via !== null
                ? [...$person($brief->drafted_by_user_id), 'via' => $brief->drafted_via]
                : null,
            'drafted_at' => $brief?->drafted_at?->toIso8601String(),
            'confirmed_at' => $last['at'] ?? null,
            'confirmed_by' => isset($last['user_id']) ? $person((int) $last['user_id']) : null,
            'unconfirmed' => $unconfirmed,
        ];
    }

    private function state(Task $task): string
    {
        return match (true) {
            $task->isArchived() => self::STATE_ARCHIVED,
            $task->finished_at !== null => self::STATE_FINISHED,
            $task->isDone() || $task->list_name === TrelloListMapper::LANE_DONE => self::STATE_COMPLETED,
            default => self::STATE_OPEN,
        };
    }

    /**
     * A manual task still carries files whose contents nobody in the CRM
     * wrote, and a board project is named after its Trello board.
     *
     * @return list<string>
     */
    private function untrusted(Task $task): array
    {
        if (self::isExternal($task)) {
            return self::CARD_PATHS;
        }

        return $task->project?->trello_board_id !== null ? ['attachments', 'project.name'] : ['attachments'];
    }

    /**
     * The description's links, then the card's attachments that point elsewhere.
     *
     * @param  list<array<string, mixed>>  $manifest
     * @return list<array{url: string, text: string|null, from: string}>
     */
    private function links(string $description, array $manifest): array
    {
        $links = [
            ...array_map(
                fn (array $link): array => [...$link, 'from' => 'description'],
                CardDescription::links($description),
            ),
            ...self::cardLinks($manifest),
        ];

        return array_values(collect($links)->unique('url')->all());
    }

    /**
     * Files on disk, then the card's files that were refused or failed, with why.
     *
     * @param  list<array<string, mixed>>  $manifest
     * @return list<array<string, mixed>>
     */
    private function attachments(Task $task, array $manifest): array
    {
        $saved = $task->attachments
            ->map(fn (TaskAttachment $attachment): array => [
                'id' => (int) $attachment->id,
                'name' => (string) $attachment->original_name,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'path' => Storage::disk('local')->path($attachment->file_path),
                'url' => route('agent.attachment', $attachment->id),
                'from' => $attachment->isFromTrello() ? 'trello' : 'upload',
                'status' => CardAttachmentPuller::STATUS_SAVED,
                'reason' => null,
            ])
            ->values()
            ->all();

        $missing = array_map(fn (array $entry): array => [
            'id' => null,
            'name' => (string) $entry['name'],
            'mime' => $entry['mime'] ?? null,
            'size' => $entry['size'] ?? null,
            'path' => null,
            'url' => null,
            'from' => 'trello',
            'status' => (string) $entry['status'],
            'reason' => $entry['reason'] ?? null,
        ], array_values(array_filter($manifest, fn (mixed $entry): bool => is_array($entry)
            && in_array($entry['status'] ?? null, [CardAttachmentPuller::STATUS_REFUSED, CardAttachmentPuller::STATUS_FAILED], true))));

        return [...$saved, ...$missing];
    }

    /**
     * @return array{minutes: int, running_timer: array{entry_id: int, started_at: string|null}|null}
     */
    private function time(Task $task): array
    {
        $running = TimeEntry::query()->where('task_id', $task->id)->whereNull('end_time')->orderByDesc('start_time')->first();

        return [
            'minutes' => (int) TimeEntry::query()->where('task_id', $task->id)->whereNotNull('end_time')->sum('duration_minutes'),
            'running_timer' => $running !== null ? [
                'entry_id' => (int) $running->id,
                'started_at' => $running->start_time?->toIso8601String(),
            ] : null,
        ];
    }
}

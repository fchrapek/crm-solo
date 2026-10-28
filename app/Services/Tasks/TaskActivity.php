<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\AgentAuditEvent;
use App\Models\AgentToken;
use App\Models\ClientLifecycleEvent;
use App\Models\Task;
use App\Models\TaskBrief;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentName;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Aktywność on the task page: what happened on one task, newest first, each
 * event once. Logged time and notes come from their own rows (an agent's
 * audit rows for them are skipped), agent edits of the task and its brief
 * from the audit trail, and the owner's finish from the task itself, the
 * one trace a web finish leaves. Owner edits of the brief or the
 * description leave no trail, so they do not show here.
 *
 * Rows are ordered by the instant, then the source, the row id and the part
 * of an audit row, so rows at one instant keep one order.
 */
final class TaskActivity
{
    public const int PREVIEW = 3;

    /** At one instant a finish reads above the edits and the timer it stopped. */
    private const int SOURCE_TIME = 1;

    private const int SOURCE_NOTE = 2;

    private const int SOURCE_AUDIT = 3;

    private const int SOURCE_FINISH = 4;

    private const int AUDIT_BATCH = 200;

    /** Audit columns of a task that name a change the owner cares about, as the page labels them. */
    private const array TASK_FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'due_date' => 'due',
        'list_name' => 'list',
        'project_id' => 'project',
        'archived_at' => 'archive',
        'priority' => 'priority',
        'parent_task_id' => 'parent',
        'is_reportable' => 'reportable',
    ];

    /** Columns a finish or reopen changes along with finished_at. */
    private const array FINISH_COLUMNS = ['finished_at', 'finished_by_user_id', 'finished_by_token_id', 'finished_via', 'finished_session_id', 'list_name', 'is_completed'];

    /**
     * The newest $limit rows and whether there are more, or every row when $limit is null.
     *
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function forTask(Task $task, ?int $limit, ?int $viewerId): array
    {
        $task->loadMissing('brief:id,task_id', 'project:id,account_id');
        $take = $limit === null ? null : $limit + 1;

        $rows = collect()
            ->concat($this->timeRows($task, $take))
            ->concat($this->noteRows($task, $take))
            ->concat($this->auditRows($task, $take))
            ->concat($this->ownerFinishRow($task))
            ->sortByDesc('order')
            ->values();

        $page = $limit === null ? $rows : $rows->take($limit)->values();
        $people = $this->people($page);

        return [
            'rows' => $page->map(fn (array $row): array => $this->present($row, $people, $viewerId))->all(),
            'more' => $limit !== null && $rows->count() > $limit,
        ];
    }

    /**
     * The running timer an agent token started on the task, if any: the live row.
     *
     * @return array<string, mixed>|null
     */
    public function live(Task $task): ?array
    {
        $entry = TimeEntry::query()
            ->where('task_id', $task->id)
            ->whereNull('end_time')
            ->whereNotNull('actor_token_id')
            ->latest('start_time')
            ->first();
        if ($entry === null || $entry->start_time === null) {
            return null;
        }

        $local = $entry->start_time->copy()->setTimezone(LocalCalendar::timezone());

        return [
            'entry_id' => $entry->id,
            'agent' => AgentName::fromToken(AgentToken::query()->whereKey($entry->actor_token_id)->value('name')),
            'started_at' => $entry->start_time->toIso8601String(),
            'time' => $local->format('H:i'),
            'description' => $this->ownText($entry->description, $task),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function timeRows(Task $task, ?int $take): Collection
    {
        return TimeEntry::query()
            ->where('task_id', $task->id)
            ->whereNotNull('end_time')
            ->orderByDesc('end_time')
            ->orderByDesc('id')
            ->when($take !== null, fn (Builder $query) => $query->limit($take))
            ->get(['id', 'description', 'title', 'duration_minutes', 'end_time', 'actor_via', 'actor_token_id', 'actor_user_id'])
            ->map(fn (TimeEntry $entry): array => [
                ...$this->base(CarbonImmutable::instance($entry->end_time), self::SOURCE_TIME, $entry->id, 0, 'time-'.$entry->id),
                'kind' => 'time',
                'via' => $entry->actor_via,
                'token_id' => $entry->actor_token_id,
                'user_id' => $entry->actor_user_id,
                'minutes' => (int) $entry->duration_minutes,
                'text' => $this->ownText($entry->description, $task) ?? $this->ownText($entry->title, $task),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function noteRows(Task $task, ?int $take): Collection
    {
        return ClientLifecycleEvent::query()
            ->where('task_id', $task->id)
            ->whereNotNull('note')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->when($take !== null, fn (Builder $query) => $query->limit($take))
            ->get(['id', 'note', 'created_at', 'user_id', 'actor_via', 'actor_token_id'])
            ->map(fn (ClientLifecycleEvent $event): array => [
                ...$this->base(CarbonImmutable::instance($event->created_at), self::SOURCE_NOTE, $event->id, 0, 'note-'.$event->id),
                'kind' => 'note',
                'via' => $event->actor_via,
                'token_id' => $event->actor_token_id,
                'user_id' => $event->user_id,
                'text' => (string) $event->note,
            ]);
    }

    /**
     * Agent edits of the task and its brief. An empty brief row created
     * before its first write, a confirmation alone and changes the page does
     * not name are left out, so the trail is read in batches until there are
     * $take rows to show or it ends.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function auditRows(Task $task, ?int $take): Collection
    {
        $briefId = $task->brief?->id;
        $query = AgentAuditEvent::query()
            ->where('account_id', $task->project?->account_id)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('target_type', 'tasks')->where('target_id', $task->id))
                ->when($briefId !== null, fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q->where('target_type', 'task_briefs')->where('target_id', $briefId))))
            ->whereIn('action', ['created', 'updated'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $rows = collect();
        for ($offset = 0; ; $offset += self::AUDIT_BATCH) {
            $batch = (clone $query)->offset($offset)->limit(self::AUDIT_BATCH)->get();
            $rows = $rows->concat($batch->flatMap(fn (AgentAuditEvent $event): array => $this->auditRow($event)));
            if (($take !== null && $rows->count() >= $take) || $batch->count() < self::AUDIT_BATCH) {
                return $rows->values();
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRow(AgentAuditEvent $event): array
    {
        $at = CarbonImmutable::instance($event->created_at);
        $actor = ['via' => $event->via, 'token_id' => $event->token_id, 'user_id' => $event->user_id];
        $before = is_array($event->changes['before'] ?? null) ? $event->changes['before'] : [];

        if ($event->target_type === 'task_briefs') {
            if ($event->action !== 'updated') {
                return [];
            }
            $fields = array_values(array_intersect_key(array_flip(TaskBrief::FIELDS), $before));

            return $fields === [] ? [] : [[...$this->base($at, self::SOURCE_AUDIT, $event->id, 0, 'audit-'.$event->id), ...$actor, 'kind' => 'brief', 'fields' => $fields]];
        }

        if ($event->action === 'created') {
            return [[...$this->base($at, self::SOURCE_AUDIT, $event->id, 0, 'audit-'.$event->id), ...$actor, 'kind' => 'task_created']];
        }

        $rows = [];
        $finish = array_key_exists('finished_at', $before);
        if ($finish) {
            $rows[] = [...$this->base($at, self::SOURCE_AUDIT, $event->id, 1, 'audit-'.$event->id.'-finish'), ...$actor, 'kind' => $before['finished_at'] === null ? 'finished' : 'reopened'];
        }

        $changed = array_diff_key($before, $finish ? array_flip(self::FINISH_COLUMNS) : []);
        $fields = array_values(array_unique(array_intersect_key(self::TASK_FIELDS, $changed)));
        if ($fields !== []) {
            $rows[] = [...$this->base($at, self::SOURCE_AUDIT, $event->id, 0, 'audit-'.$event->id), ...$actor, 'kind' => 'task', 'fields' => $fields];
        }

        return $rows;
    }

    /**
     * A finish made outside an agent call. Only the current one is known: a
     * reopen clears it and a later finish replaces it.
     *
     * @return list<array<string, mixed>>
     */
    private function ownerFinishRow(Task $task): array
    {
        if ($task->finished_at === null || $task->finished_via !== null) {
            return [];
        }
        $at = CarbonImmutable::instance($task->finished_at);

        return [[
            ...$this->base($at, self::SOURCE_FINISH, (int) $task->id, 0, 'finish-'.$task->id),
            'kind' => 'finished',
            'via' => null,
            'token_id' => null,
            'user_id' => $task->finished_by_user_id,
        ]];
    }

    /**
     * @return array{at: CarbonImmutable, source: int, id: int, part: int, key: string, order: string}
     */
    private function base(CarbonImmutable $at, int $source, int $id, int $part, string $key): array
    {
        return [
            'at' => $at,
            'source' => $source,
            'id' => $id,
            'part' => $part,
            'key' => $key,
            'order' => sprintf('%s|%d|%010d|%d', $at->utc()->format('Y-m-d\TH:i:s\Z'), $source, $id, $part),
        ];
    }

    /**
     * Token and user names for the page's rows, in two queries.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{tokens: Collection<int, string>, users: Collection<int, string>}
     */
    private function people(Collection $rows): array
    {
        $tokenIds = $rows->pluck('token_id')->filter()->unique()->values();
        $userIds = $rows->whereNull('via')->pluck('user_id')->filter()->unique()->values();

        return [
            'tokens' => $tokenIds->isEmpty() ? collect() : AgentToken::query()->whereIn('id', $tokenIds)->pluck('name', 'id'),
            'users' => $userIds->isEmpty() ? collect() : User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn (User $user): array => [$user->id => (string) $user->name]),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{tokens: Collection<int, string>, users: Collection<int, string>}  $people
     * @return array<string, mixed>
     */
    private function present(array $row, array $people, ?int $viewerId): array
    {
        /** @var CarbonImmutable $at */
        $at = $row['at'];
        $local = $at->setTimezone(LocalCalendar::timezone());

        if ($row['via'] !== null) {
            $actor = ['kind' => 'agent', 'name' => AgentName::fromToken($row['token_id'] !== null ? $people['tokens']->get($row['token_id']) : null)];
        } elseif ($row['user_id'] === null || $row['user_id'] === $viewerId) {
            $actor = ['kind' => 'owner', 'name' => null];
        } else {
            $actor = ['kind' => 'person', 'name' => $people['users']->get($row['user_id'])];
        }

        return [
            'key' => $row['key'],
            'kind' => $row['kind'],
            'actor' => $actor,
            'at' => $at->toIso8601String(),
            'day' => $local->toDateString(),
            'time' => $local->format('H:i'),
            'minutes' => $row['minutes'] ?? null,
            'text' => $row['text'] ?? null,
            'fields' => $row['fields'] ?? [],
        ];
    }

    /** Text worth showing: a timer's default description is the task's own name. */
    private function ownText(?string $text, Task $task): ?string
    {
        $text = mb_trim((string) $text);

        return $text === '' || $text === mb_trim((string) $task->name) ? null : $text;
    }
}

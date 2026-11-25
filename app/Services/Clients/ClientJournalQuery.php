<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\ClientFieldDefinition;
use App\Models\ClientFieldEvent;
use App\Models\ClientLifecycleEvent;
use App\Models\ClientMonthSettlementEvent;
use App\Models\ClientRetainer;
use App\Models\OutboundMessage;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Clients\Fields\ClientFieldsQuery;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\MergeProp;

/** Figma: Client / Journal. Undo per session, entry kinds and project domains are not built. */
final class ClientJournalQuery
{
    public const int WORK_PAGE = 10;

    public const int NOTES_PAGE = 5;

    /** Journal › Relationship: the whole list, a page this long. */
    public const int RELATION_PAGE = 20;

    public const string RELATION_VIEW = 'relationship';

    /** The first page grows to hold every new row, up to this many. */
    public const int FIRST_PAGE_MAX = 50;

    /** The tie order of the relationship stream inside one second. */
    private const array NOTE_RANKS = ['note' => 2, 'field' => 1, 'settlement' => 0];

    public function __construct(private readonly ClientJournal $journal) {}

    /**
     * The two lists page by cursor; Older reloads only its list, which the client appends.
     *
     * @param  CarbonInterface  $since  where New starts for this user (ClientViews)
     * @param  string|null  $workCursor  the `next` of the work list page shown last
     * @param  string|null  $notesCursor  the `next` of the journal entries page shown last
     * @param  string|null  $view  `relationship` for the sub-view with every entry, a longer page of them
     * @return array{journal: Closure, journal_view: string|null, journal_sms: Closure, journal_work: MergeProp, journal_notes: MergeProp}
     */
    public function props(Client $client, CarbonInterface $since, ?string $workCursor = null, ?string $notesCursor = null, ?string $view = null): array
    {
        $since = CarbonImmutable::instance($since)->setTimezone('UTC');
        $view = $view === self::RELATION_VIEW ? $view : null;
        $page = $view === null ? self::NOTES_PAGE : self::RELATION_PAGE;

        return [
            'journal_view' => $view,
            'journal' => fn (): array => $this->summary($client, $since),
            // The account's text messages to this client, newest first; null when the account sends none.
            'journal_sms' => fn (): ?array => app(ClientFieldsQuery::class)->uses($client->account_id) ? app(ClientFieldsQuery::class)->messages($client, 20) : null,
            'journal_work' => Inertia::merge(fn (): array => $this->work($client, $since, $this->workCursor($workCursor)))->append('rows', 'key'),
            'journal_notes' => Inertia::merge(fn (): array => $this->notes($client, $this->notesCursor($notesCursor), $page))->append('rows', 'key'),
        ];
    }

    /**
     * The tasks a time entry of this client can be tied to, the ones touched last first.
     *
     * @return list<array{id: int, name: string, project_name: string|null}>
     */
    public function taskOptions(Client $client): array
    {
        return Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id)->where('account_id', $client->account_id))
            ->whereNull('archived_at')
            ->with('project:id,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Task $task): array => ['id' => $task->id, 'name' => $task->name, 'project_name' => $task->project?->name])
            ->all();
    }

    /**
     * @return array{stage: string, since: string|null, retainer_from: string|null}
     */
    public function stage(Client $client): array
    {
        $stage = (string) ($client->lifecycle_stage ?? 'active');
        $entered = $client->lifecycleEvents()
            ->where('account_id', $client->account_id)
            ->where('to_stage', $stage)
            ->where(fn ($q) => $q->whereNull('from_stage')->orWhereColumn('from_stage', '!=', 'to_stage'))
            ->value('created_at');
        $retainer = ClientRetainer::query()
            ->where('client_id', $client->id)
            ->where('account_id', $client->account_id)
            ->leadingOn(LocalCalendar::todayDate())
            ->value('effective_from');

        return [
            'stage' => $stage,
            'since' => $entered !== null ? $this->localDate(CarbonImmutable::parse($entered, 'UTC')) : null,
            'retainer_from' => $retainer !== null ? CarbonImmutable::parse($retainer)->toDateString() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Client $client, CarbonImmutable $since): array
    {
        $fresh = $this->journal->rowsSince($client, $since);
        $period = LocalCalendar::currentMonth();

        return [
            'since' => $this->localDate($since),
            'since_today' => $this->localDate($since) === LocalCalendar::today()->toDateString(),
            'summary' => [
                'rows' => $fresh->count(),
                'notes' => $fresh->sum(fn (array $row): int => count($row['event_ids'])),
                'field_changes' => ClientFieldEvent::query()->where('client_id', $client->id)->where('created_at', '>=', $since)->count(),
                'sms' => OutboundMessage::query()->where('client_id', $client->id)->whereIn('status', [OutboundMessage::SENT, OutboundMessage::SIMULATED])->where('sent_at', '>=', $since)->count(),
                'person_minutes' => (int) $client->timeEntries()
                    ->where('account_id', $client->account_id)
                    ->where('start_time', '>=', $since)
                    ->whereNull('actor_via')
                    ->sum('duration_minutes'),
            ],
            'stage' => $this->stage($client),
            // Every entry the relationship list holds: notes and stage changes, field changes, closes and reopens.
            'notes_total' => ClientLifecycleEvent::query()->where('client_id', $client->id)->where('account_id', $client->account_id)->count()
                + ClientFieldEvent::query()->where('client_id', $client->id)->count()
                + ClientMonthSettlementEvent::query()->where('client_id', $client->id)->count(),
            'period' => $period,
            'projects' => $this->projects($client, $period),
            'tasks' => $this->taskOptions($client),
        ];
    }

    private function workCursor(?string $cursor): ?CarbonImmutable
    {
        return $cursor !== null && preg_match('/^\d{1,12}$/', $cursor) === 1 ? CarbonImmutable::createFromTimestamp((int) $cursor, 'UTC') : null;
    }

    /**
     * "time-kind-id", the last row a page showed; an older cursor of a time alone (or time-id) reads as before that second.
     *
     * @return array{0: CarbonImmutable, 1: int|null, 2: int|null}|null
     */
    private function notesCursor(?string $cursor): ?array
    {
        if ($cursor === null || preg_match('/^(\d{1,12})(?:-([0-2])-(\d{1,10})|-\d{1,10})?$/', $cursor, $m) !== 1) {
            return null;
        }

        return [CarbonImmutable::createFromTimestamp((int) $m[1], 'UTC'), isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
    }

    /**
     * The first page holds every new row (up to FIRST_PAGE_MAX), so the summary does not count rows it hides.
     *
     * @return array{rows: list<array<string, mixed>>, next: string|null}
     */
    private function work(Client $client, CarbonImmutable $since, ?CarbonImmutable $before): array
    {
        $size = $before === null
            ? max(self::WORK_PAGE, min(self::FIRST_PAGE_MAX, $this->journal->workCountSince($client, $since)))
            : self::WORK_PAGE;
        $page = $this->journal->workPage($client, $before, $size);
        $shown = $page['rows'];

        $entries = TimeEntry::query()
            ->where('account_id', $client->account_id)
            ->whereIn('id', $shown->pluck('entry_ids')->flatten())
            // The row links its task, so only a task of the account's own projects counts as one.
            ->with(['task' => fn ($task) => $task->select(['id', 'name', 'project_id'])->whereHas('project', fn ($project) => $project->where('account_id', $client->account_id))])
            ->get(['id', 'task_id', 'title', 'description', 'start_time', 'end_time', 'duration_minutes', 'actor_via', 'billable'])
            ->keyBy('id');
        $events = ClientLifecycleEvent::query()
            ->where('account_id', $client->account_id)
            ->whereIn('id', $shown->pluck('event_ids')->flatten())
            ->get(['id', 'note', 'actor_via'])
            ->keyBy('id');
        $writes = $this->writes($client, $shown);

        return [
            'rows' => $shown->map(fn (array $row): array => $this->workRow($row, $entries, $events, $writes, $since))->values()->all(),
            'next' => $page['next'] !== null ? (string) $page['next']->getTimestamp() : null,
        ];
    }

    /**
     * AgentAuditEvent rows of each shown session that target the session's own entries and journal events.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function writes(Client $client, Collection $rows): array
    {
        $sessions = $rows->where('kind', 'session');
        if ($sessions->isEmpty()) {
            return [];
        }

        $audit = AgentAuditEvent::query()
            ->where('account_id', $client->account_id)
            ->whereIn('session_id', $sessions->pluck('session'))
            ->whereIn('target_type', ['time_entries', 'client_lifecycle_events'])
            ->get(['session_id', 'target_type', 'target_id']);

        return $sessions->mapWithKeys(function (array $row) use ($audit): array {
            $count = $audit->where('session_id', $row['session'])->filter(fn (AgentAuditEvent $event): bool => $event->target_type === 'time_entries'
                ? in_array((int) $event->target_id, $row['entry_ids'], true)
                : in_array((int) $event->target_id, $row['event_ids'], true))->count();

            return [$row['session'] => $count];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, TimeEntry>  $entries
     * @param  Collection<int, ClientLifecycleEvent>  $events
     * @param  array<string, int>  $writes
     * @return array<string, mixed>
     */
    private function workRow(array $row, Collection $entries, Collection $events, array $writes, CarbonImmutable $since): array
    {
        $members = collect($row['entry_ids'])->map(fn (int $id): ?TimeEntry => $entries->get($id))->filter()->values();
        $lead = $members->first();
        $note = $lead === null ? $events->get($row['event_ids'][0] ?? 0) : null;
        $title = $lead?->task?->name ?? $lead?->title;
        $detail = $lead?->description ?: ($lead?->task !== null && $lead->title !== $lead->task->name ? $lead->title : null);

        return [
            'key' => $row['key'],
            'kind' => $row['kind'],
            'date' => $this->localDate($row['at']),
            'title' => $title !== null && $title !== '' ? (string) $title : null,
            'subtitle' => $note !== null ? $note->note : ($detail !== null && $detail !== '' ? (string) $detail : null),
            'via' => $lead?->actor_via ?? $note?->actor_via,
            'task_id' => $lead?->task?->id,
            'minutes' => $members->contains(fn (TimeEntry $entry): bool => $entry->end_time === null) ? null : (int) $members->sum('duration_minutes'),
            'running' => $members->contains(fn (TimeEntry $entry): bool => $entry->end_time === null),
            'running_since' => $members->firstWhere('end_time', null)?->start_time?->toIso8601String(),
            'writes' => $row['session'] !== null ? ($writes[$row['session']] ?? 0) : null,
            'is_new' => $this->isNew($row['at'], $since),
            'entry' => $members->count() === 1 && $lead->end_time !== null ? [
                'id' => $lead->id,
                'title' => $lead->title,
                'description' => $lead->description,
                'task_id' => $lead->task_id,
                'start_time' => $lead->start_time?->toIso8601String(),
                'end_time' => $lead->end_time->toIso8601String(),
                'billable' => (bool) $lead->billable,
            ] : null,
        ];
    }

    /**
     * Journal entries, field changes and closes as one stream, newest first by (time, kind, id), a page of exactly `$page`
     * rows. The cursor is the last row shown, so the next page starts right after it whichever kind it was.
     *
     * @param  array{0: CarbonImmutable, 1: int|null, 2: int|null}|null  $before
     * @return array{rows: list<array<string, mixed>>, next: string|null}
     */
    private function notes(Client $client, ?array $before, int $page = self::NOTES_PAGE): array
    {
        $older = function ($query, int $rank) use ($before) {
            if ($before === null) {
                return $query;
            }
            [$at, $kind, $id] = $before;

            return $query->where(function ($older) use ($at, $kind, $id, $rank): void {
                $older->where('created_at', '<', $at);
                if ($kind !== null && $rank < $kind) {
                    $older->orWhere('created_at', $at);
                }
                if ($kind !== null && $rank === $kind) {
                    $older->orWhere(fn ($tie) => $tie->where('created_at', $at)->where('id', '<', $id));
                }
            });
        };
        $newest = fn ($query, int $rank) => $older($query, $rank)->orderByDesc('created_at')->orderByDesc('id')->limit($page + 1);

        $entries = $newest(ClientLifecycleEvent::query()->where('client_id', $client->id)->where('account_id', $client->account_id), self::NOTE_RANKS['note'])
            ->with('user:id,first_name,last_name')
            ->get(['id', 'user_id', 'from_stage', 'to_stage', 'note', 'created_at', 'actor_via'])
            ->map(fn (ClientLifecycleEvent $event): array => [
                'key' => 'note-'.$event->id,
                'kind' => 'note',
                'at' => $event->created_at,
                'rid' => $event->id,
                'id' => $event->id,
                'date' => $this->localDate($event->created_at),
                'via' => $event->actor_via,
                'author' => $event->user !== null ? mb_trim($event->user->first_name.' '.$event->user->last_name) : null,
                'from_stage' => $event->from_stage,
                'to_stage' => $event->to_stage,
                'stage_change' => $event->from_stage !== $event->to_stage,
                'note' => $event->note,
            ]);

        $settlements = $newest(ClientMonthSettlementEvent::query()->where('client_id', $client->id), self::NOTE_RANKS['settlement'])
            ->with('actor:id,first_name,last_name')
            ->get()
            ->map(fn (ClientMonthSettlementEvent $event): array => [
                'key' => 'settlement-'.$event->id,
                'kind' => 'settlement',
                'at' => $event->created_at,
                'rid' => $event->id,
                'date' => $this->localDate($event->created_at),
                'via' => null,
                'author' => $event->actor !== null ? mb_trim($event->actor->first_name.' '.$event->actor->last_name) : null,
                'settlement' => [
                    'action' => $event->action,
                    'period' => $event->period,
                    'used' => isset($event->snapshot['used']) ? (float) $event->snapshot['used'] : null,
                    'contracted' => isset($event->snapshot['contracted']) ? (float) $event->snapshot['contracted'] : null,
                    'closing' => isset($event->snapshot['closing']) ? (float) $event->snapshot['closing'] : null,
                    'source' => $event->snapshot['source'] ?? null,
                ],
            ]);

        $definitions = ClientFieldDefinition::query()->where('account_id', $client->account_id)->get()->keyBy('key');
        $fields = $newest(ClientFieldEvent::query()->where('client_id', $client->id), self::NOTE_RANKS['field'])
            ->with('user:id,first_name,last_name')
            ->get()
            ->map(fn (ClientFieldEvent $event): array => [
                ...app(ClientFieldsQuery::class)->event($event, $definitions->get($event->field_key)),
                'key' => 'field-'.$event->id,
                'field_key' => $event->field_key,
                'kind' => 'field',
                'at' => $event->created_at,
                'rid' => $event->id,
                'date' => $this->localDate($event->created_at),
                'via' => null,
                'author' => $event->user?->name,
            ]);

        $stream = $entries->concat($settlements)->concat($fields)
            ->sort(fn (array $a, array $b): int => [$b['at']->getTimestamp(), self::NOTE_RANKS[$b['kind']], $b['rid']] <=> [$a['at']->getTimestamp(), self::NOTE_RANKS[$a['kind']], $a['rid']])
            ->values();
        $shown = $stream->take($page);
        $last = $shown->last();

        return [
            'rows' => $shown->map(fn (array $row): array => array_diff_key($row, ['at' => true, 'rid' => true]))->values()->all(),
            'next' => $stream->count() > $page && $last !== null ? $last['at']->getTimestamp().'-'.self::NOTE_RANKS[$last['kind']].'-'.$last['rid'] : null,
        ];
    }

    /**
     * Live projects with the time logged on them this local month, busiest first.
     *
     * @return list<array{id: int, name: string, minutes: int, trello_url: string|null}>
     */
    private function projects(Client $client, string $period): array
    {
        [$from, $to] = LocalCalendar::monthRange($period);
        $minutes = TimeEntry::query()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(duration_minutes) as minutes')
            ->pluck('minutes', 'project_id');

        return Project::query()
            ->where('client_id', $client->id)
            ->where('account_id', $client->account_id)
            ->whereNull('archived_at')
            ->get(['id', 'name', 'trello_url'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => (string) $project->name,
                'minutes' => (int) ($minutes[$project->id] ?? 0),
                'trello_url' => $project->trello_url ?: null,
            ])
            ->sortBy([['minutes', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /** The summary's boundary, compared to the second as its SQL does. */
    private function isNew(CarbonInterface $at, CarbonImmutable $since): bool
    {
        return $at->getTimestamp() >= $since->getTimestamp();
    }

    private function localDate(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(LocalCalendar::timezone())->toDateString();
    }
}

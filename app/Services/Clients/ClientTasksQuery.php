<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Services\Time\LoggedMinutes;
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class ClientTasksQuery
{
    public const int LANE_LIMIT = 5;

    public const int ORDER_OPEN_LIMIT = 10;

    public const int ORDER_DONE_LIMIT = 5;

    public const int ORDER_TIME_LIMIT = 5;

    /** The lanes in the switch's order, keyed by the slug the expand link carries. */
    public const array LANES = [
        'doing' => TrelloListMapper::LANE_DOING,
        'testing' => TrelloListMapper::LANE_TESTING,
        'todo' => TrelloListMapper::LANE_TODO,
    ];

    /** Backlog and every list outside the lanes: one group, closed until asked for. */
    public const string OTHER = 'other';

    private ?Project $only = null;

    /**
     * The project page: the same lanes, rows and task form, narrowed to one project.
     *
     * @return array{tasks: array<string, mixed>}
     */
    public function forProject(Client $client, Project $project, ?string $expanded = null): array
    {
        $narrowed = clone $this;
        $narrowed->only = $project;

        return $narrowed->props($client, $expanded, ClientBillingMode::RETAINER);
    }

    /**
     * @return array{tasks: array<string, mixed>}
     */
    public function props(Client $client, ?string $expanded = null, ?string $mode = null): array
    {
        if (($mode ?? app(ClientBillingMode::class)->of($client)) === ClientBillingMode::ORDER) {
            return $this->orderProps($client, in_array($expanded, ['open', 'done', 'time'], true) ? $expanded : null);
        }

        $expanded = array_key_exists((string) $expanded, self::LANES) || in_array($expanded, ['done', self::OTHER], true) ? $expanded : null;

        $counts = collect(self::LANES)->map(fn (string $list) => $this->inLane($this->openTasks($client), $list)->count());

        $rows = collect(self::LANES)->map(fn (string $list, string $key) => $this->inLane($this->openTasks($client), $list)
            ->orderBy('tasks.position')
            ->orderBy('tasks.id')
            ->when($key !== $expanded, fn (Builder $q) => $q->limit(self::LANE_LIMIT))
            ->with('project:id,name')
            ->get(['tasks.*']));

        $otherCount = $this->onOtherLists($client)->count();
        if ($otherCount > 0) {
            $counts[self::OTHER] = $otherCount;
            $rows[self::OTHER] = $expanded === self::OTHER
                ? $this->onOtherLists($client)->orderBy('tasks.list_name')->orderBy('tasks.position')->orderBy('tasks.id')->with('project:id,name')->get(['tasks.*'])
                : collect();
        }

        $running = $this->runningByTask($client, $rows->flatten()->pluck('id')->all());
        $logged = LoggedMinutes::byTask((int) $client->account_id, $rows->flatten()->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $picked = $this->pickedToday($client);
        $done = $this->latestDone($client)
            ->when($expanded !== 'done', fn (Builder $q) => $q->limit(self::LANE_LIMIT))
            ->get(['tasks.*']);

        return [
            'tasks' => [
                'mode' => ClientBillingMode::RETAINER,
                'expanded' => $expanded,
                'lanes' => $rows->map(fn (Collection $tasks, string $key) => [
                    'key' => $key,
                    'count' => (int) $counts[$key],
                    'rows' => $tasks->map(fn (Task $task) => $this->row($task, $running->get($task->id, collect()), $picked, $logged))->values()->all(),
                ])->values()->all(),
                'done' => ['count' => $this->doneTasks($client)->count(), 'rows' => $this->doneRows($client, $done)],
                'projects' => $this->projects($client, ClientBillingMode::RETAINER),
                'repositories' => $this->repositoryPaths($client),
                'reportable_by_default' => Task::reportableByDefault($client->id),
            ],
        ];
    }

    /**
     * The open tasks the Tasks tab shows, and so what its badge counts.
     *
     * @return Builder<Task>
     */
    public function visibleOpenTasks(Client $client): Builder
    {
        return $this->openTasks($client);
    }

    /**
     * The client's live projects with their open tasks, for the project filter and the project page's pills, and what
     * stands behind each: its board, the month close (while the account has the module on) and its repositories.
     *
     * @return list<array{id: int, name: string, open: int, is_private: bool, in_month_close: bool, repositories: int}>
     */
    public function projects(Client $client, ?string $mode = null): array
    {
        $open = $this->visibleOpenTasks($client)
            ->groupBy('tasks.project_id')
            ->selectRaw('tasks.project_id, COUNT(*) as open')
            ->pluck('open', 'tasks.project_id');
        $monthClose = $client->account->shows('billing', Auth::user()) && ($client->account->usesModule('month_close') || app(MonthCloseWorklist::class)->holds($client));

        return $this->liveProjects($client)->withCount('repositories')->get(['id', 'name', 'trello_board_id', 'include_in_month_close'])
            ->map(fn (Project $p): array => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'open' => (int) ($open[$p->id] ?? 0),
                'is_private' => $p->trello_board_id === null,
                'in_month_close' => $monthClose && (bool) $p->include_in_month_close,
                'repositories' => (int) $p->repositories_count,
            ])
            ->all();
    }

    /**
     * The tasks a new one may sit under: every open task of the client's live projects (of the one project on its page), with its project.
     *
     * @return list<array{id: int, name: string, project_id: int}>
     */
    public function parentOptions(Client $client, ?Project $only = null): array
    {
        $narrowed = clone $this;
        $narrowed->only = $only;

        return $narrowed->openTasks($client)
            ->orderBy('tasks.name')
            ->get(['tasks.id', 'tasks.name', 'tasks.project_id'])
            ->map(fn (Task $task): array => ['id' => (int) $task->id, 'name' => $task->name, 'project_id' => (int) $task->project_id])
            ->all();
    }

    /** @return Builder<Task> */
    private function onOtherLists(Client $client): Builder
    {
        return $this->openTasks($client)->whereNotNull('tasks.list_name')->whereNotIn('tasks.list_name', array_values(self::LANES));
    }

    /**
     * @return array{tasks: array<string, mixed>}
     */
    private function orderProps(Client $client, ?string $expanded): array
    {
        $bounded = fn (Builder $query, string $part, int $limit): Collection => $query
            ->when($expanded !== $part, fn (Builder $q) => $q->limit($limit))
            ->get(['tasks.*']);

        $open = $bounded(
            $this->openTasks($client)
                ->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('tasks.due_date')
                ->orderBy('tasks.position')
                ->orderBy('tasks.id')
                ->with('project:id,name'),
            'open',
            self::ORDER_OPEN_LIMIT,
        );
        $done = $bounded($this->latestDone($client), 'done', self::ORDER_DONE_LIMIT);
        $running = $this->runningByTask($client, $open->pluck('id')->all());
        $logged = LoggedMinutes::byTask((int) $client->account_id, $open->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $picked = $this->pickedToday($client);
        $entries = $client->timeEntries()
            ->with(['task:id,name', 'project:id,name'])
            ->latest('start_time')
            ->orderByDesc('id')
            ->when($expanded !== 'time', fn ($q) => $q->limit(self::ORDER_TIME_LIMIT))
            ->get();
        $short = LegalName::short($client->name);

        return [
            'tasks' => [
                'mode' => ClientBillingMode::ORDER,
                'expanded' => $expanded,
                'open' => [
                    'count' => $this->visibleOpenTasks($client)->count(),
                    'rows' => $open->map(fn (Task $task) => $this->row($task, $running->get($task->id, collect()), $picked, $logged))->values()->all(),
                ],
                'done' => [
                    'count' => $this->doneTasks($client)->count(),
                    'rows' => $this->doneRows($client, $done),
                ],
                'time' => [
                    'hours' => round((int) $client->timeEntries()->sum('duration_minutes') / 60, 2),
                    'count' => $client->timeEntries()->count(),
                    'rows' => $entries->map(fn (TimeEntry $entry): array => [
                        'id' => $entry->id,
                        'date' => CarbonImmutable::instance($entry->start_time)->setTimezone(LocalCalendar::timezone())->toDateString(),
                        'label' => $entry->task?->name ?? $entry->title ?? ($entry->project !== null && $entry->project->name !== 'General' ? $entry->project->name : $short),
                        'minutes' => (int) $entry->duration_minutes,
                    ])->values()->all(),
                ],
                'projects' => $this->projects($client, ClientBillingMode::ORDER),
                'repositories' => [],
                'reportable_by_default' => Task::reportableByDefault($client->id),
            ],
        ];
    }

    /**
     * @return Builder<Task>
     */
    private function latestDone(Client $client): Builder
    {
        return $this->doneTasks($client)
            ->orderByRaw('COALESCE(tasks.finished_at, tasks.archived_at, tasks.updated_at) DESC')
            ->orderByDesc('tasks.id')
            ->with('project:id,name');
    }

    /**
     * @param  Collection<int, Task>  $done
     * @return list<array<string, mixed>>
     */
    private function doneRows(Client $client, Collection $done): array
    {
        $logged = LoggedMinutes::byTask((int) $client->account_id, $done->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $done->map(fn (Task $task): array => [
            ...$this->row($task, collect(), null, $logged),
            'done' => true,
            'done_on' => CarbonImmutable::instance($task->finished_at ?? $task->archived_at ?? $task->updated_at)->setTimezone(LocalCalendar::timezone())->toDateString(),
        ])->values()->all();
    }

    /**
     * Done is whatever the open scope leaves out, so Trello Done, archived and ticked tasks all count;
     * a card in a skipped column is no work unless it was finished before it was filed there.
     *
     * @return Builder<Task>
     */
    private function doneTasks(Client $client): Builder
    {
        return Task::query()
            ->whereHas('project', fn (Builder $q) => $q
                ->where('client_id', $client->id)
                ->where('account_id', $client->account_id))
            ->whereNotIn('tasks.id', $this->openTasks($client)->select('tasks.id'))
            ->when($this->only !== null, fn (Builder $q) => $q->where('tasks.project_id', $this->only->id))
            ->work();
    }

    /**
     * @return Builder<Task>
     */
    private function openTasks(Client $client): Builder
    {
        return Task::query()
            ->open()
            ->whereHas('project', fn (Builder $q) => $q
                ->where('client_id', $client->id)
                ->where('account_id', $client->account_id))
            ->when($this->only !== null, fn (Builder $q) => $q->where('tasks.project_id', $this->only->id));
    }

    /**
     * A CRM task saved without a list waits in To-Do; Backlog stays on the board that owns it.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function inLane(Builder $query, string $list): Builder
    {
        return $list === TrelloListMapper::LANE_TODO
            ? $query->where(fn (Builder $q) => $q->where('tasks.list_name', $list)->orWhereNull('tasks.list_name'))
            : $query->where('tasks.list_name', $list);
    }

    /**
     * @return Builder<Project>
     */
    private function liveProjects(Client $client): Builder
    {
        return Project::query()
            ->where('client_id', $client->id)
            ->where('account_id', $client->account_id)
            ->whereNull('archived_at')
            ->when($this->only !== null, fn (Builder $q) => $q->whereKey($this->only->id))
            ->orderBy('name');
    }

    /**
     * The owner's running timer is his to stop; an agent's shows as the agent at work.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @param  Collection<int, int>  $picked  today's picks, task ids as keys
     * @param  array<int, int>  $logged  minutes by task id
     * @return array<string, mixed>
     */
    private function row(Task $task, Collection $entries, ?Collection $picked = null, array $logged = []): array
    {
        $mine = $entries->first(fn (TimeEntry $e) => $e->actor_via === null);

        return [
            'task_id' => (int) $task->id,
            'name' => $task->name,
            'project' => $task->relationLoaded('project') ? $task->project?->name : null,
            'done' => $task->isDone(),
            'in_progress' => $task->isInProgress(),
            'can_untick' => $task->canUnfinish(),
            'due' => $task->dueDay(),
            // A task saved without a list waits in To-Do.
            'list' => $task->list_name ?? TrelloListMapper::LANE_TODO,
            'minutes' => $logged[$task->id] ?? 0,
            'running_id' => $mine?->id,
            'running_since' => $mine?->start_time?->toIso8601String(),
            'agent_live' => $entries->contains(fn (TimeEntry $e) => $e->actor_via !== null),
            'picked' => $picked?->has($task->id) ?? false,
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function pickedToday(Client $client): Collection
    {
        return DayPick::live()->where('account_id', $client->account_id)->whereDate('date', LocalCalendar::today()->toDateString())->pluck('task_id')->flip();
    }

    /**
     * @param  list<int>  $taskIds
     * @return Collection<int, Collection<int, TimeEntry>>
     */
    private function runningByTask(Client $client, array $taskIds): Collection
    {
        if ($taskIds === []) {
            return collect();
        }

        return TimeEntry::query()
            ->where('account_id', $client->account_id)
            ->whereIn('task_id', $taskIds)
            ->whereNull('end_time')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get(['id', 'task_id', 'actor_via', 'start_time'])
            ->groupBy('task_id');
    }

    /**
     * Local working copies of the client's live projects, the paths `client:config` hands an agent.
     *
     * @return list<array{path: string, project: string}>
     */
    private function repositoryPaths(Client $client): array
    {
        return Repository::query()
            ->whereIn('project_id', $this->liveProjects($client)->select('id'))
            ->whereNotNull('local_path')
            ->where('local_path', '!=', '')
            ->with('project:id,name')
            ->orderBy('local_path')
            ->get()
            ->unique('local_path')
            ->map(fn (Repository $repo) => ['path' => (string) $repo->local_path, 'project' => (string) $repo->project?->name])
            ->values()
            ->all();
    }
}

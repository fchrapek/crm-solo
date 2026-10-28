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
use App\Support\LegalName;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

        $expanded = array_key_exists((string) $expanded, self::LANES) ? $expanded : null;

        $counts = collect(self::LANES)->map(fn (string $list) => $this->inLane($this->openTasks($client), $list)->count());

        $rows = collect(self::LANES)->map(fn (string $list, string $key) => $this->inLane($this->openTasks($client), $list)
            ->orderBy('tasks.position')
            ->orderBy('tasks.id')
            ->when($key !== $expanded, fn (Builder $q) => $q->limit(self::LANE_LIMIT))
            ->get(['tasks.*']));

        $running = $this->runningByTask($client, $rows->flatten()->pluck('id')->all());
        $picked = $this->pickedToday($client);

        return [
            'tasks' => [
                'mode' => ClientBillingMode::RETAINER,
                'expanded' => $expanded,
                'lanes' => $rows->map(fn (Collection $tasks, string $key) => [
                    'key' => $key,
                    'count' => (int) $counts[$key],
                    'rows' => $tasks->map(fn (Task $task) => $this->row($task, $running->get($task->id, collect()), $picked))->values()->all(),
                ])->values()->all(),
                'projects' => $this->liveProjects($client)->get(['id', 'name'])
                    ->map(fn (Project $p) => ['id' => (int) $p->id, 'name' => $p->name])
                    ->all(),
                'repositories' => $this->repositoryPaths($client),
            ],
        ];
    }

    /**
     * The open tasks the Zadania tab shows, and so what its badge counts: the three lanes for a retainer, the whole open list for an order.
     *
     * @return Builder<Task>
     */
    public function visibleOpenTasks(Client $client, ?string $mode = null): Builder
    {
        if (($mode ?? app(ClientBillingMode::class)->of($client)) === ClientBillingMode::ORDER) {
            return $this->openTasks($client);
        }

        return $this->openTasks($client)->where(fn (Builder $q) => $q
            ->whereIn('tasks.list_name', array_values(self::LANES))
            ->orWhereNull('tasks.list_name'));
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
                ->orderBy('tasks.id'),
            'open',
            self::ORDER_OPEN_LIMIT,
        );
        $done = $bounded(
            $this->doneTasks($client)
                ->orderByRaw('COALESCE(tasks.finished_at, tasks.archived_at, tasks.updated_at) DESC')
                ->orderByDesc('tasks.id'),
            'done',
            self::ORDER_DONE_LIMIT,
        );
        $running = $this->runningByTask($client, $open->pluck('id')->all());
        $picked = $this->pickedToday($client);
        $minutes = TimeEntry::query()
            ->where('account_id', $client->account_id)
            ->whereIn('task_id', $done->pluck('id'))
            ->groupBy('task_id')
            ->selectRaw('task_id, SUM(duration_minutes) as minutes')
            ->pluck('minutes', 'task_id');
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
                    'count' => $this->visibleOpenTasks($client, ClientBillingMode::ORDER)->count(),
                    'rows' => $open->map(fn (Task $task) => $this->row($task, $running->get($task->id, collect()), $picked))->values()->all(),
                ],
                'done' => [
                    'count' => $this->doneTasks($client)->count(),
                    'rows' => $done->map(fn (Task $task): array => [
                        ...$this->row($task, collect()),
                        'done' => true,
                        'done_on' => CarbonImmutable::instance($task->finished_at ?? $task->archived_at ?? $task->updated_at)->setTimezone(LocalCalendar::timezone())->toDateString(),
                        'minutes' => (int) ($minutes[$task->id] ?? 0),
                    ])->values()->all(),
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
                'projects' => $this->liveProjects($client)->get(['id', 'name'])
                    ->map(fn (Project $p) => ['id' => (int) $p->id, 'name' => $p->name])
                    ->all(),
                'repositories' => [],
            ],
        ];
    }

    /**
     * Done is whatever the open scope leaves out, so Trello Done, archived and ticked tasks all count.
     *
     * @return Builder<Task>
     */
    private function doneTasks(Client $client): Builder
    {
        return Task::query()
            ->whereHas('project', fn (Builder $q) => $q
                ->where('client_id', $client->id)
                ->where('account_id', $client->account_id))
            ->whereNotIn('tasks.id', $this->openTasks($client)->select('tasks.id'));
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
     * @return array<string, mixed>
     */
    private function row(Task $task, Collection $entries, ?Collection $picked = null): array
    {
        $mine = $entries->first(fn (TimeEntry $e) => $e->actor_via === null);

        return [
            'task_id' => (int) $task->id,
            'name' => $task->name,
            'done' => $task->isDone(),
            'can_untick' => $task->canUnfinish(),
            'due' => $task->dueDay(),
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
        return DayPick::where('account_id', $client->account_id)->whereDate('date', LocalCalendar::today()->toDateString())->pluck('task_id')->flip();
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

<?php

declare(strict_types=1);

namespace App\Services\Day;

use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\HiddenTaskGroup;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Tasks\TaskCompletion;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The day as a unit: up to five picks, a running timer, and a close that
 * stamps the day in the month card. "Today" is the display timezone's date.
 */
final class DayPlanner
{
    public const int MAX_PICKS = 5;

    public const string STATE_PAPER = 'paper';

    public const string STATE_TIMER = 'timer';

    public const string STATE_DONE = 'done';

    public function __construct(private readonly TaskCompletion $completion) {}

    /**
     * One day's tile: closed, open, rest, today, future or stamped.
     * See the Day tile component in Figma (Solo · Components).
     */
    public static function tileState(CarbonImmutable $day, CarbonImmutable $today, bool $isClosed): string
    {
        $weekend = $day->dayOfWeekIso >= 6;

        if ($day->isSameDay($today)) {
            return $isClosed ? 'stamped' : 'today';
        }
        if ($isClosed) {
            return 'closed';
        }
        if ($weekend) {
            // Weekends are days off whether past or ahead; a worked one counts once closed.
            return 'rest';
        }

        return $day->lt($today) ? 'open' : 'future';
    }

    public function today(): CarbonImmutable
    {
        return LocalCalendar::today();
    }

    /** The last day a pick can be set for: the Sunday that ends next week. */
    public function lastPlannableDay(): CarbonImmutable
    {
        return $this->today()->endOfWeek(CarbonImmutable::SUNDAY)->addWeek()->startOfDay();
    }

    public function isPlannable(CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->today()->toDateString() && $day <= $this->lastPlannableDay()->toDateString();
    }

    public function running(int $accountId): ?TimeEntry
    {
        return $this->runningAll($accountId)->first();
    }

    /**
     * Every running timer, newest first. Parallel sessions are valid (agent work bills alongside).
     *
     * @return Collection<int, TimeEntry>
     */
    public function runningAll(int $accountId): Collection
    {
        return TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereNull('end_time')
            ->with(['task:id,name,project_id', 'client:id,name', 'project:id,name'])
            ->latest('start_time')
            ->get();
    }

    public function isClosed(int $accountId, CarbonImmutable $date): bool
    {
        return DayClose::where('account_id', $accountId)->whereDate('date', $date->toDateString())->exists();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function picks(int $accountId, CarbonImmutable $date): Collection
    {
        $picks = DayPick::query()
            ->where('account_id', $accountId)
            ->whereDate('date', $date->toDateString())
            ->with('task.project.client:id,name')
            ->orderBy('position')
            ->get();

        $minutes = $this->minutesByTask($accountId, $date, $picks->pluck('task_id')->all());
        $running = TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereIn('task_id', $picks->pluck('task_id'))
            ->whereNull('end_time')
            ->orderBy('start_time')
            ->get(['id', 'task_id'])
            ->unique('task_id')
            ->pluck('id', 'task_id');

        return $picks->filter(fn (DayPick $pick) => $pick->task !== null)->values()
            ->map(fn (DayPick $pick) => $this->pickRow($pick, (int) ($minutes[$pick->task_id] ?? 0), $running[$pick->task_id] ?? null));
    }

    /**
     * Seven dates from Monday with their picks and minutes, read in one pass;
     * a week shows no timers, so no running_id.
     *
     * @return list<array{date: string, plannable: bool, closed: bool, picks: Collection<int, array<string, mixed>>}>
     */
    public function week(int $accountId, CarbonImmutable $monday): array
    {
        $sunday = $monday->addDays(6);
        $picks = DayPick::query()
            ->where('account_id', $accountId)
            ->whereDate('date', '>=', $monday->toDateString())
            ->whereDate('date', '<=', $sunday->toDateString())
            ->with('task.project.client:id,name')
            ->orderBy('position')
            ->get()
            ->filter(fn (DayPick $pick) => $pick->task !== null)
            ->groupBy(fn (DayPick $pick) => $pick->date->toDateString());
        $closed = DayClose::where('account_id', $accountId)
            ->whereDate('date', '>=', $monday->toDateString())
            ->whereDate('date', '<=', $sunday->toDateString())
            ->pluck('date')
            ->map(fn ($day) => mb_substr((string) $day, 0, 10))
            ->flip();
        [$from] = LocalCalendar::dayRange($monday);
        [, $to] = LocalCalendar::dayRange($sunday);
        $minutes = TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereIn('task_id', $picks->flatten()->pluck('task_id')->unique())
            ->whereNotNull('end_time')
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->get(['task_id', 'start_time', 'duration_minutes'])
            ->groupBy(fn (TimeEntry $entry) => $entry->task_id.'|'.CarbonImmutable::instance($entry->start_time)->setTimezone(LocalCalendar::timezone())->toDateString())
            ->map(fn (Collection $entries) => (int) $entries->sum('duration_minutes'));

        return collect(range(0, 6))->map(function (int $offset) use ($monday, $picks, $closed, $minutes): array {
            $date = $monday->addDays($offset);
            $day = $date->toDateString();

            return [
                'date' => $day,
                'plannable' => $this->isPlannable($date),
                'closed' => $closed->has($day),
                'picks' => ($picks[$day] ?? collect())->values()->map(fn (DayPick $pick) => $this->pickRow($pick, $minutes["{$pick->task_id}|{$day}"] ?? 0, null)),
            ];
        })->all();
    }

    /**
     * Unfinished picks from earlier days that wait on the given day: the task
     * is still open, not picked for that day and the pick was not dismissed.
     * A task picked on several past days shows once, from its latest day.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function carried(int $accountId, CarbonImmutable $date): Collection
    {
        $pickedOnDate = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id');
        $latest = DayPick::query()
            ->where('account_id', $accountId)
            ->whereDate('date', '<', $date->toDateString())
            ->whereNull('dismissed_at')
            ->whereNotIn('task_id', $pickedOnDate)
            ->whereIn('task_id', $this->openTasks($accountId)->select('tasks.id'))
            ->groupBy('task_id')
            ->selectRaw('task_id, MAX(date) as latest');

        return DayPick::query()
            ->select('day_picks.*')
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.task_id', '=', 'day_picks.task_id')->on('latest.latest', '=', 'day_picks.date'))
            ->where('day_picks.account_id', $accountId)
            ->with('task.project.client:id,name')
            ->orderByDesc('day_picks.date')
            ->get()
            ->filter(fn (DayPick $pick) => $pick->task !== null)
            ->map(fn (DayPick $pick) => [
                'id' => $pick->id,
                'from' => $pick->date->toDateString(),
                ...$this->taskSummary($pick->task),
            ])
            ->values();
    }

    /** Stops an earlier day's unfinished pick from waiting on today; the past plan keeps it. */
    public function dismissCarried(int $accountId, DayPick $pick): void
    {
        if ($pick->account_id !== $accountId || $pick->date->toDateString() >= $this->today()->toDateString()) {
            throw ValidationException::withMessages(['task_id' => __('Only a pick from an earlier day can be dismissed.')]);
        }

        // Every past pick of the task goes, or the next older one would take its place.
        DayPick::where('account_id', $accountId)
            ->where('task_id', $pick->task_id)
            ->whereDate('date', '<', $this->today()->toDateString())
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]);
    }

    /**
     * Running timers no row on the day shows: a second timer on the same task,
     * one whose task was deleted, one with no task at all. Every running timer
     * stays visible and stoppable whatever state its task is in.
     *
     * @param  Collection<int, TimeEntry>  $timers
     * @param  Collection<int, array<string, mixed>>  ...$rows
     * @return Collection<int, TimeEntry>
     */
    public function looseTimers(Collection $timers, Collection ...$rows): Collection
    {
        $shown = collect($rows)->flatMap(fn (Collection $list) => $list->pluck('running_id'))->filter()->flip();

        return $timers->reject(fn (TimeEntry $entry) => $shown->has($entry->id))->values();
    }

    /**
     * Every open task, grouped by client, work in hand first (Doing, To-Do,
     * Testing, then custom lanes, Backlog last). Picked tasks carry a flag
     * instead of disappearing, so the list stays the same shape while choosing.
     *
     * @return Collection<int, array{client: string, tasks: Collection<int, array<string, mixed>>}>
     */
    public function openList(int $accountId, CarbonImmutable $date, bool $withHidden = false): Collection
    {
        $picked = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id')->flip();
        $tasks = $withHidden ? $this->openTasks($accountId) : $this->visibleTasks($accountId);

        return $this->inWorkOrder($tasks)
            ->get()
            ->groupBy(fn (Task $task) => $task->project?->client?->name ?? __('No client'))
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Collection $tasks, string $client) => [
                'client' => $client,
                'tasks' => $tasks->map(fn (Task $task) => [...$this->taskSummary($task), 'picked' => $picked->has($task->id)])->values(),
            ])
            ->values();
    }

    /**
     * What the plan page offers for the date: today's unfinished picks first,
     * then the soonest due day, then work in hand, leaving out the date's own
     * picks. `leftover` counts today's unfinished picks among them, `open`
     * every open task (the full list's size).
     *
     * @return array{tasks: Collection<int, array<string, mixed>>, leftover: int, open: int}
     */
    public function suggestions(int $accountId, CarbonImmutable $date, int $limit = 3): array
    {
        $picked = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id')->flip();
        $today = DayPick::where('account_id', $accountId)->whereDate('date', $this->today()->toDateString())->orderBy('position')->pluck('task_id')->flip();
        $open = $this->inWorkOrder($this->visibleTasks($accountId))->get();

        $candidates = $open
            ->reject(fn (Task $task) => $picked->has($task->id))
            ->sortBy([
                fn (Task $a, Task $b) => ($today[$a->id] ?? PHP_INT_MAX) <=> ($today[$b->id] ?? PHP_INT_MAX),
                fn (Task $a, Task $b) => [$a->dueDay() === null, $a->dueDay()] <=> [$b->dueDay() === null, $b->dueDay()],
            ])
            ->values();

        return [
            'tasks' => $candidates->take($limit)->map(fn (Task $task) => $this->taskSummary($task))->values(),
            'leftover' => $candidates->filter(fn (Task $task) => $today->has($task->id))->count(),
            'open' => $open->count(),
        ];
    }

    /**
     * Open tasks past their due day: how many, and the oldest due day.
     *
     * @return array{count: int, oldest: ?string}
     */
    public function overdue(int $accountId): array
    {
        // The bound only narrows the query; Task::isOverdue decides, since a CRM date and a card's instant are read differently.
        $days = $this->openTasks($accountId)
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '<', $this->today()->addDay()->toDateString())
            ->get()
            ->filter(fn (Task $task) => $task->isOverdue())
            ->map(fn (Task $task) => $task->dueDay());

        return ['count' => $days->count(), 'oldest' => $days->min()];
    }

    /**
     * Work done today outside the plan: every task with a timer today (running,
     * or logged since midnight) that is not one of the date's picks.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function offPlan(int $accountId, CarbonImmutable $date): Collection
    {
        $picked = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id');

        $entries = TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereNotNull('task_id')
            ->whereNotIn('task_id', $picked)
            ->where(fn ($q) => $q->whereNull('end_time')->orWhere(fn ($day) => $this->startedOn($day, $date)))
            ->with('task.project.client:id,name')
            ->orderBy('start_time')
            ->get();

        // An entry whose task is gone still bills its project, but has no task row to show here.
        return $entries->filter(fn (TimeEntry $entry) => $entry->task !== null)->groupBy('task_id')->map(function (Collection $taskEntries) {
            $running = $taskEntries->firstWhere('end_time', null);

            return [
                ...$this->taskSummary($taskEntries->first()->task),
                'minutes' => (int) $taskEntries->whereNotNull('end_time')->sum('duration_minutes'),
                'running_id' => $running?->id,
            ];
        })->values();
    }

    /**
     * Ticks a task through the shared completion path, which stops its
     * running timers first. Stop alone is a pause.
     */
    public function completeTask(Task $task): void
    {
        $this->completion->finish($task);
    }

    /**
     * Reverses a tick through the shared path. A timer the tick stopped stays
     * stopped; starting again is a new click.
     */
    public function uncompleteTask(Task $task): void
    {
        $this->completion->unfinish($task);
    }

    /**
     * The one way to plan a task, for the day screens and the agents: the
     * date is plannable, the day is not closed, then addPick's rules.
     */
    public function pick(int $accountId, CarbonImmutable $date, int $taskId): DayPick
    {
        return $this->holdingDay($accountId, $date, function () use ($accountId, $date, $taskId): DayPick {
            $this->guardPlannable($accountId, $date);

            return $this->addPick($accountId, $date, $taskId);
        });
    }

    /** Takes a pick off its day, under the same rules as adding one. */
    public function unpick(int $accountId, DayPick $pick): void
    {
        $date = CarbonImmutable::parse($pick->date->toDateString(), LocalCalendar::timezone());

        $this->holdingDay($accountId, $date, function () use ($accountId, $date, $pick): void {
            $this->guardPlannable($accountId, $date);
            $pick->delete();
        });
    }

    /**
     * Each pick takes one of the day's MAX_PICKS slots, unique per account and
     * date in the database: two requests racing for the last slot cannot both
     * land, and the loser re-reads the day and gets the same answer as if it
     * had come second.
     */
    public function addPick(int $accountId, CarbonImmutable $date, int $taskId): DayPick
    {
        $task = $this->openTasks($accountId)->where('tasks.id', $taskId)->first();
        if ($task === null) {
            throw ValidationException::withMessages(['task_id' => __('This task cannot be picked.')]);
        }

        for ($attempt = 0; $attempt <= self::MAX_PICKS; $attempt++) {
            $existing = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->get(['task_id', 'slot', 'position']);
            if ($existing->contains('task_id', $taskId)) {
                throw ValidationException::withMessages(['task_id' => __('This task is already picked for the day.')]);
            }

            $slot = collect(range(1, self::MAX_PICKS))->diff($existing->pluck('slot')->filter())->first();
            if ($existing->count() >= self::MAX_PICKS || $slot === null) {
                throw ValidationException::withMessages(['task_id' => __('A day holds at most :max picks.', ['max' => self::MAX_PICKS])]);
            }

            try {
                return DayPick::create([
                    'account_id' => $accountId,
                    'task_id' => $taskId,
                    'date' => $date->toDateString(),
                    'slot' => $slot,
                    'position' => (int) $existing->max('position') + 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another request took this slot or this task in the meantime; read the day again.
            }
        }

        throw ValidationException::withMessages(['task_id' => __('A day holds at most :max picks.', ['max' => self::MAX_PICKS])]);
    }

    /**
     * Stamps the day, then checks for a running timer and takes the stamp back
     * if one exists. Starting a timer inserts the entry before it removes the
     * stamp, so whichever order the two land in, a closed day never has a
     * timer running.
     */
    public function close(int $accountId, CarbonImmutable $date): DayClose
    {
        return $this->holdingDay($accountId, $date, fn (): DayClose => $this->stampClosed($accountId, $date));
    }

    public function reopen(int $accountId, CarbonImmutable $date): void
    {
        DayClose::where('account_id', $accountId)->whereDate('date', $date->toDateString())->delete();
    }

    /**
     * The month card: one entry per day of the month holding the date and
     * its tile state, plus the closed-weekday count against all weekdays.
     *
     * @return array{month: string, days: list<array{date: string, weekday: int, state: string}>, closed: int, weekdays: int}
     */
    public function month(int $accountId, CarbonImmutable $today): array
    {
        $start = $today->startOfMonth();
        $end = $today->endOfMonth();
        $closed = DayClose::where('account_id', $accountId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->flip();

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->dayOfWeekIso,
                'state' => self::tileState($day, $today, $closed->has($day->toDateString())),
            ];
        }

        $weekdays = collect($days)->where('weekday', '<=', 5);

        return [
            'month' => $start->toDateString(),
            'days' => $days,
            'closed' => $weekdays->whereIn('state', ['closed', 'stamped'])->count(),
            'weekdays' => $weekdays->count(),
        ];
    }

    /**
     * @return array{logged_minutes: int, clients: int}
     */
    public function totals(int $accountId, CarbonImmutable $date): array
    {
        $entries = TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereNotNull('end_time')
            ->where(fn ($q) => $this->startedOn($q, $date))
            ->get(['duration_minutes', 'client_id']);

        return [
            'logged_minutes' => (int) $entries->sum('duration_minutes'),
            'clients' => $entries->pluck('client_id')->filter()->unique()->count(),
        ];
    }

    /**
     * The groups the full list can hide: one per Trello list of a board
     * project, one per project otherwise, with the open tasks each holds.
     *
     * @return Collection<int, array{project_id: int, list_name: ?string, label: string, client: ?string, count: int, hidden: bool}>
     */
    public function hideableGroups(int $accountId): Collection
    {
        $hidden = $this->hiddenGroups($accountId);

        return $this->openTasks($accountId)->get()
            ->groupBy(fn (Task $task) => $task->project_id.'|'.($this->listKey($task) ?? ''))
            ->map(function (Collection $tasks) use ($hidden): array {
                $task = $tasks->first();
                $list = $this->listKey($task);

                return [
                    'project_id' => $task->project_id,
                    'list_name' => $list,
                    // a list name repeats across boards, so a list group also names its project
                    'label' => $list !== null ? "{$list} · {$task->project->name}" : $task->project->name,
                    'client_id' => $task->project->client_id,
                    'client' => $task->project->client?->name,
                    'count' => $tasks->count(),
                    'hidden' => $hidden->contains(fn (HiddenTaskGroup $group) => $group->project_id === $task->project_id && in_array($group->list_name, [null, $list], true)),
                ];
            })
            ->sortBy([['client', 'asc'], ['label', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Hides the group, or shows it again when it is hidden.
     */
    public function toggleHidden(int $accountId, int $projectId, ?string $listName): void
    {
        $key = HiddenTaskGroup::key($projectId, $listName);

        Cache::lock("hidden-groups:{$accountId}", 10)->block(5, function () use ($accountId, $projectId, $listName, $key): void {
            $existing = HiddenTaskGroup::where('account_id', $accountId)->where('group_key', $key)->first();

            $existing !== null
                ? $existing->delete()
                : HiddenTaskGroup::create(['account_id' => $accountId, 'project_id' => $projectId, 'list_name' => $listName, 'group_key' => $key]);
        });
    }

    /**
     * Hides every group the client has now, or shows them all again when each one is hidden; a later project stays visible.
     */
    public function toggleClientHidden(int $accountId, int $clientId): void
    {
        Cache::lock("hidden-groups:{$accountId}", 10)->block(5, function () use ($accountId, $clientId): void {
            $groups = $this->hideableGroups($accountId)->where('client_id', $clientId);

            if ($groups->every('hidden')) {
                HiddenTaskGroup::where('account_id', $accountId)->whereIn('project_id', $groups->pluck('project_id')->unique())->delete();

                return;
            }
            foreach ($groups->where('hidden', false) as $group) {
                HiddenTaskGroup::firstOrCreate(
                    ['account_id' => $accountId, 'group_key' => HiddenTaskGroup::key($group['project_id'], $group['list_name'])],
                    ['project_id' => $group['project_id'], 'list_name' => $group['list_name']],
                );
            }
        });
    }

    public function restoreHidden(int $accountId): void
    {
        Cache::lock("hidden-groups:{$accountId}", 10)->block(5, fn () => HiddenTaskGroup::where('account_id', $accountId)->delete());
    }

    /**
     * @return Collection<int, HiddenTaskGroup>
     */
    public function hiddenGroups(int $accountId): Collection
    {
        return HiddenTaskGroup::where('account_id', $accountId)->get(['project_id', 'list_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function pickRow(DayPick $pick, int $minutes, ?int $runningId): array
    {
        return [
            'id' => $pick->id,
            'minutes' => $minutes,
            ...$this->taskSummary($pick->task),
            // The timer this row shows with Stop, done or not: a card completed by the sync keeps the owner's timer running.
            'running_id' => $runningId,
        ];
    }

    private function stampClosed(int $accountId, CarbonImmutable $date): DayClose
    {
        $existing = fn () => DayClose::where('account_id', $accountId)->whereDate('date', $date->toDateString())->first();
        try {
            $close = $existing() ?? DayClose::create(['account_id' => $accountId, 'date' => $date->toDateString(), 'closed_at' => now()]);
        } catch (UniqueConstraintViolationException $e) {
            $close = $existing() ?? throw $e;
        }

        if ($this->running($accountId) !== null) {
            $close->delete();

            throw ValidationException::withMessages(['close' => __('Stop the running timer before closing the day.')]);
        }

        return $close;
    }

    /**
     * A plan changes only inside isPlannable's window, and not on a closed
     * day: reopen the day first.
     */
    private function guardPlannable(int $accountId, CarbonImmutable $date): void
    {
        if (! $this->isPlannable($date)) {
            throw ValidationException::withMessages(['task_id' => __('Picks can be set from today to the end of next week.')]);
        }
        if ($this->isClosed($accountId, $date)) {
            throw ValidationException::withMessages(['task_id' => __('This day is closed. Reopen it to change its plan.')]);
        }
    }

    /**
     * A plan change and the day's close take turns, so a pick cannot land on
     * a day that closed between the check and the write.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function holdingDay(int $accountId, CarbonImmutable $date, callable $callback): mixed
    {
        return Cache::lock("day-plan:{$accountId}:{$date->toDateString()}", 10)->block(5, $callback);
    }

    /** Work in hand first: Doing, To-Do, Testing, then custom lanes, Backlog last; by name within a lane. */
    private function inWorkOrder($query)
    {
        return $query
            ->orderByRaw("CASE tasks.list_name WHEN 'Doing' THEN 1 WHEN 'To-Do' THEN 2 WHEN 'Testing' THEN 3 WHEN 'Backlog' THEN 5 ELSE 4 END")
            ->orderBy('tasks.name');
    }

    private function listKey(Task $task): ?string
    {
        return $task->project?->trello_board_id !== null ? $task->list_name : null;
    }

    /** Open tasks minus the hidden groups; a list group matches null-safely, so a task with no list never drops out. */
    private function visibleTasks(int $accountId)
    {
        $query = $this->openTasks($accountId);
        foreach ($this->hiddenGroups($accountId) as $group) {
            $query->where(fn ($q) => $group->list_name === null
                ? $q->where('tasks.project_id', '!=', $group->project_id)
                : $q->where('tasks.project_id', '!=', $group->project_id)->orWhereNull('tasks.list_name')->orWhere('tasks.list_name', '!=', $group->list_name));
        }

        return $query;
    }

    private function openTasks(int $accountId)
    {
        return Task::query()
            ->select('tasks.*')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->where('projects.account_id', $accountId)
            ->open()
            ->with('project.client:id,name');
    }

    /**
     * @return array<string, mixed>
     */
    private function taskSummary(Task $task): array
    {
        return [
            'task_id' => $task->id,
            'name' => $task->name,
            'client' => $task->project?->client?->name,
            'project' => $task->project?->name,
            'done' => $task->isDone(),
            'can_untick' => $task->canUnfinish(),
        ];
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, int>
     */
    private function minutesByTask(int $accountId, CarbonImmutable $date, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        return TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereIn('task_id', $taskIds)
            ->whereNotNull('end_time')
            ->where(fn ($q) => $this->startedOn($q, $date))
            ->selectRaw('task_id, SUM(duration_minutes) as minutes')
            ->groupBy('task_id')
            ->pluck('minutes', 'task_id')
            ->map(fn ($m) => (int) $m)
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<TimeEntry>|\Illuminate\Database\Query\Builder  $query
     */
    private function startedOn($query, CarbonImmutable $date): void
    {
        [$from, $to] = LocalCalendar::dayRange($date);
        $query->where('start_time', '>=', $from)->where('start_time', '<', $to);
    }
}

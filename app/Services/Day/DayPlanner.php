<?php

declare(strict_types=1);

namespace App\Services\Day;

use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Tasks\TaskCompletion;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
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

        return $picks->filter(fn (DayPick $pick) => $pick->task !== null)->values()->map(fn (DayPick $pick) => [
            'id' => $pick->id,
            'minutes' => (int) ($minutes[$pick->task_id] ?? 0),
            ...$this->taskSummary($pick->task),
            // The timer this row shows with Stop, done or not: a card completed by the sync keeps the owner's timer running.
            'running_id' => $running[$pick->task_id] ?? null,
        ]);
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
    public function openList(int $accountId, CarbonImmutable $date): Collection
    {
        $picked = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id')->flip();

        return $this->openTasks($accountId)
            ->orderByRaw("CASE tasks.list_name WHEN 'Doing' THEN 1 WHEN 'To-Do' THEN 2 WHEN 'Testing' THEN 3 WHEN 'Backlog' THEN 5 ELSE 4 END")
            ->orderBy('tasks.name')
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

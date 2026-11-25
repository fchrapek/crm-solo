<?php

declare(strict_types=1);

namespace App\Services\Day;

use App\Models\DayAddRequest;
use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\HiddenTaskGroup;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Tasks\TaskCompletion;
use App\Services\Tasks\TaskWriter;
use App\Services\Time\LoggedMinutes;
use App\Support\LikePattern;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;

/**
 * The day as a unit: up to five picks, a running timer, and a close that
 * stamps the day in the month card. "Today" is the display timezone's date.
 */
final class DayPlanner
{
    use DetectsConcurrencyErrors;

    /** A full day: more can be picked, with a warning. */
    public const int MAX_PICKS = 5;

    public const string STATE_PAPER = 'paper';

    public const string STATE_TIMER = 'timer';

    public const string STATE_DONE = 'done';

    /** The slot column is an unsigned tiny integer. */
    private const int SLOTS = 255;

    private const int PICK_ATTEMPTS = 6;

    private const int PLAN_LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly TaskCompletion $completion,
        private readonly TaskWriter $writer,
    ) {}

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

    /** The earliest day the account has anything on: a pick, a close or logged time. Null on an empty account. */
    public function firstDay(int $accountId): ?CarbonImmutable
    {
        $entry = TimeEntry::where('account_id', $accountId)->min('start_time');
        $days = array_filter([
            DayPick::where('account_id', $accountId)->min('date'),
            DayClose::where('account_id', $accountId)->min('date'),
            $entry === null ? null : CarbonImmutable::parse($entry, (string) config('app.timezone'))->setTimezone(LocalCalendar::timezone())->toDateString(),
        ]);

        return $days === [] ? null : CarbonImmutable::parse(mb_substr((string) min(array_map(fn ($day) => mb_substr((string) $day, 0, 10), $days)), 0, 10), LocalCalendar::timezone());
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
            ->live()
            ->where('account_id', $accountId)
            ->whereDate('date', $date->toDateString())
            ->with('task.project.client:id,name')
            ->orderBy('position')
            ->get();

        $minutes = $this->minutesByTask($accountId, $date, $picks->pluck('task_id')->all());
        $running = $this->runningIds($accountId, $picks->pluck('task_id')->all());

        return $this->withLoggedTime($accountId, $picks->filter(fn (DayPick $pick) => $pick->task !== null)->values()
            ->map(fn (DayPick $pick) => $this->pickRow($pick, (int) ($minutes[$pick->task_id] ?? 0), $running[$pick->task_id] ?? null)));
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
            ->live()
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
        $pickedOnDate = DayPick::live()->where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id');
        $latest = DayPick::query()
            ->live()
            ->where('account_id', $accountId)
            ->whereDate('date', '<', $date->toDateString())
            ->whereNull('dismissed_at')
            ->whereNotIn('task_id', $pickedOnDate)
            ->whereNotIn('task_id', $this->workOn($accountId, $date)->select('task_id'))
            ->whereIn('task_id', $this->openTasks($accountId)->select('tasks.id'))
            ->groupBy('task_id')
            ->selectRaw('task_id, MAX(date) as latest');

        return $this->withLoggedTime($accountId, DayPick::query()
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
            ->values());
    }

    /** Stops an earlier day's unfinished pick from waiting on today; the past plan keeps it. */
    public function dismissCarried(int $accountId, DayPick $pick): void
    {
        if ($pick->account_id !== $accountId || $pick->date->toDateString() >= $this->today()->toDateString()) {
            throw ValidationException::withMessages(['task_id' => __('Only a pick from an earlier day can be dismissed.')]);
        }

        // Every past pick of the task goes, or the next older one would take its place.
        $this->holdingPlan($accountId, fn () => DayPick::where('account_id', $accountId)
            ->where('task_id', $pick->task_id)
            ->whereDate('date', '<', $this->today()->toDateString())
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]));
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
        $picked = DayPick::live()->where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id')->flip();
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
        $ids = $this->inPlanOrder($this->visibleTasks($accountId)->whereNotIn('tasks.id', $this->livePicks($accountId, $date)), $accountId, $limit);
        $today = $this->livePicks($accountId, $this->today())->pluck('task_id')->map(fn ($id) => (int) $id)->flip();
        $running = $this->runningIds($accountId, $ids);

        return [
            // Each says why it is offered: left over from today, else its due day.
            'tasks' => $this->withLoggedTime($accountId, $this->inOrder(Task::query()->with('project.client:id,name'), $ids)->map(fn (Task $task) => [
                ...$this->taskSummary($task),
                'from_today' => $today->has($task->id),
                'running_id' => $running[$task->id] ?? null,
            ])->values()),
            'leftover' => $this->visibleTasks($accountId)
                ->whereNotIn('tasks.id', $this->livePicks($accountId, $date))
                ->whereIn('tasks.id', $this->livePicks($accountId, $this->today()))
                ->count(),
            'open' => $this->visibleTasks($accountId)->count(),
        ];
    }

    /**
     * The add field's matches: open tasks whose name holds the text, hidden groups included, in the order the
     * suggestions use, leaving out the date's own picks; ordered, cut and counted in the database. `total` counts
     * every match.
     *
     * @return array{tasks: Collection<int, array{task_id: int, name: string, client: ?string, project: ?string}>, total: int}
     */
    public function matching(int $accountId, CarbonImmutable $date, string $text, int $limit = 6): array
    {
        $found = LikePattern::where(
            Task::query()
                ->join('projects', 'projects.id', '=', 'tasks.project_id')
                ->where('projects.account_id', $accountId)
                ->open()
                ->whereNotIn('tasks.id', $this->livePicks($accountId, $date)),
            'tasks.name',
            mb_trim($text),
        );
        $total = $found->clone()->count();
        $ids = $this->inPlanOrder($found, $accountId, $limit);
        $tasks = $this->inOrder(Task::query()->select(['tasks.id', 'tasks.name', 'tasks.project_id'])->with(['project:id,name,client_id', 'project.client:id,name']), $ids)
            ->map(fn (Task $task): array => ['task_id' => $task->id, 'name' => $task->name, 'client' => $task->project?->client?->name, 'project' => $task->project?->name])
            ->values();

        return ['tasks' => $tasks, 'total' => $total];
    }

    /**
     * Open tasks worked on or changed last, for the add field before anything is typed; the date's picks stay out.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recentTasks(int $accountId, CarbonImmutable $date, int $limit = 3): Collection
    {
        $worked = TimeEntry::query()->selectRaw('MAX(start_time)')->whereColumn('time_entries.task_id', 'tasks.id');

        return $this->visibleTasks($accountId)
            ->whereNotIn('tasks.id', $this->livePicks($accountId, $date))
            ->orderByRaw('COALESCE(('.$worked->toSql().'), tasks.updated_at) DESC', $worked->getBindings())
            ->orderByDesc('tasks.id')
            ->limit($limit)
            ->get()
            ->map(fn (Task $task) => $this->taskSummary($task))
            ->values();
    }

    /**
     * A new task straight onto a day: written by the task form's own path and picked under the plan's turn, in one
     * transaction, so a refused pick leaves no task behind. A request id is kept per account with its payload: sent
     * again with the same payload it returns the task it made, with another payload or once that task is gone it is
     * refused, and it never makes a second task.
     *
     * @return array{task: Task, pick: DayPick, replayed: bool}
     *
     * @throws AddRequestConflict
     */
    public function addNewTask(int $accountId, CarbonImmutable $date, Project $project, string $name, ?string $requestId = null): array
    {
        $fingerprint = hash('sha256', (string) json_encode([$date->toDateString(), $project->id, $name]));
        $recorded = fn (): ?DayAddRequest => $requestId === null ? null : DayAddRequest::where('account_id', $accountId)->where('request_id', $requestId)->first();
        $replay = function (DayAddRequest $request) use ($accountId, $date, $fingerprint): array {
            if (! hash_equals($request->fingerprint, $fingerprint)) {
                throw AddRequestConflict::otherPayload();
            }
            $task = Task::query()->whereKey($request->task_id)->whereHas('project', fn (Builder $q) => $q->where('account_id', $accountId))->first();
            $pick = $task === null ? null : DayPick::live()->where('account_id', $accountId)->where('task_id', $task->id)->whereDate('date', $date->toDateString())->first();
            if ($task === null || $pick === null) {
                throw AddRequestConflict::taskGone();
            }

            return ['task' => $task, 'pick' => $pick, 'replayed' => true];
        };

        try {
            return $this->holdingPlan($accountId, function () use ($accountId, $date, $project, $name, $requestId, $fingerprint, $recorded, $replay): array {
                if (($request = $recorded()) !== null) {
                    return $replay($request);
                }
                $this->guardPlannable($accountId, $date);
                $task = $this->writer->create($project, ['name' => $name]);
                $pick = $this->addPick($accountId, $date, $task->id);
                if ($requestId !== null) {
                    DayAddRequest::create(['account_id' => $accountId, 'request_id' => $requestId, 'fingerprint' => $fingerprint, 'task_id' => $task->id]);
                }

                return ['task' => $task, 'pick' => $pick, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same request won a race and this one rolled back whole: read the winner after the rollback.
            $request = $recorded();

            return $request !== null ? $replay($request) : throw $e;
        }
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
    public function offPlan(int $accountId, CarbonImmutable $date, bool $withRunning = true): Collection
    {
        // Moved picks count too: their time shows with them, under what left the day.
        $picked = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->pluck('task_id');

        $entries = $this->workOn($accountId, $date, $withRunning)
            ->whereNotIn('task_id', $picked)
            ->with('task.project.client:id,name')
            ->orderBy('start_time')
            ->get();

        // An entry whose task is gone still bills its project, but has no task row to show here.
        return $this->withLoggedTime($accountId, $entries->filter(fn (TimeEntry $entry) => $entry->task !== null)->groupBy('task_id')->map(function (Collection $taskEntries) {
            $running = $taskEntries->firstWhere('end_time', null);

            return [
                ...$this->taskSummary($taskEntries->first()->task),
                'minutes' => (int) $taskEntries->whereNotNull('end_time')->sum('duration_minutes'),
                'running_id' => $running?->id,
            ];
        })->values());
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
     * date is plannable, the day is not closed, then addPick's rules. The new
     * pick and the move of the task's other picks land together.
     */
    public function pick(int $accountId, CarbonImmutable $date, int $taskId): DayPick
    {
        return $this->holdingPlan($accountId, function () use ($accountId, $date, $taskId): DayPick {
            $elsewhere = $this->plannedElsewhere($accountId, $date, $taskId);
            $this->guardPlannable($accountId, $date, moving: $elsewhere->isNotEmpty());
            $pick = $this->addPick($accountId, $date, $taskId);
            DayPick::whereKey($elsewhere->modelKeys())->update(['moved_to' => $date->toDateString()]);

            return $pick;
        });
    }

    /**
     * The days after `$date` in its week with how many picks each holds, for moving a pick within the week.
     *
     * @return list<array{date: string, picks: int}>
     */
    public function restOfWeek(int $accountId, CarbonImmutable $date): array
    {
        $sunday = $date->endOfWeek(CarbonImmutable::SUNDAY);
        $counts = DayPick::query()
            ->live()
            ->where('account_id', $accountId)
            ->whereDate('date', '>', $date->toDateString())
            ->whereDate('date', '<=', $sunday->toDateString())
            ->get(['date'])
            ->countBy(fn (DayPick $pick) => $pick->date->toDateString());
        $days = [];
        for ($day = $date->addDay(); $day->toDateString() <= $sunday->toDateString(); $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'picks' => (int) ($counts[$day->toDateString()] ?? 0)];
        }

        return $days;
    }

    /**
     * What left the day for another: the task, its time on the day and where it went.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function moved(int $accountId, CarbonImmutable $date): Collection
    {
        $picks = DayPick::query()
            ->whereNotNull('moved_to')
            ->where('account_id', $accountId)
            ->whereDate('date', $date->toDateString())
            ->with('task.project.client:id,name')
            ->orderBy('position')
            ->get()
            ->filter(fn (DayPick $pick) => $pick->task !== null)
            ->values();
        $minutes = $this->minutesByTask($accountId, $date, $picks->pluck('task_id')->all());

        return $picks->map(fn (DayPick $pick): array => [
            ...$this->taskSummary($pick->task),
            'minutes' => (int) ($minutes[$pick->task_id] ?? 0),
            'moved_to' => $pick->moved_to->toDateString(),
        ]);
    }

    /** Takes a pick off its day: any day from today on, as a moved pick may sit past the planning window, never a closed one. */
    public function unpick(int $accountId, DayPick $pick): void
    {
        $date = CarbonImmutable::parse($pick->date->toDateString(), LocalCalendar::timezone());

        $this->holdingPlan($accountId, function () use ($accountId, $date, $pick): void {
            $this->guardPlannable($accountId, $date, moving: true);
            $pick->delete();
        });
    }

    /**
     * Each pick takes the day's first free slot, unique per account and date in
     * the database: of two requests racing for one slot the loser re-reads the
     * day and takes the next. A day past MAX_PICKS is full, not closed to more.
     */
    public function addPick(int $accountId, CarbonImmutable $date, int $taskId): DayPick
    {
        $task = $this->openTasks($accountId)->where('tasks.id', $taskId)->first();
        if ($task === null) {
            throw ValidationException::withMessages(['task_id' => __('This task cannot be picked.')]);
        }

        for ($attempt = 0; $attempt < self::PICK_ATTEMPTS; $attempt++) {
            $existing = DayPick::where('account_id', $accountId)->whereDate('date', $date->toDateString())->get(['id', 'task_id', 'slot', 'position', 'moved_to']);
            $same = $existing->firstWhere('task_id', $taskId);
            if ($same !== null && $same->moved_to === null) {
                throw ValidationException::withMessages(['task_id' => __('This task is already picked for the day.')]);
            }
            if ($same !== null) {
                // Back on a day it was moved from: the day's own pick returns, as the unique task per day asks.
                $same->update(['moved_to' => null, 'position' => (int) $existing->max('position') + 1]);

                return $same;
            }

            $slot = collect(range(1, self::SLOTS))->diff($existing->pluck('slot')->filter())->first();
            if ($slot === null) {
                break;
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

        throw ValidationException::withMessages(['task_id' => __('This task could not be picked. Try again.')]);
    }

    /**
     * Stamps the day, then checks for a running timer and rolls the stamp back
     * if one exists, so a refused close is never seen. Starting a timer writes
     * its entry before it removes the stamp, so whichever order the two land
     * in, a closed day never has a timer running.
     */
    public function close(int $accountId, CarbonImmutable $date): DayClose
    {
        return $this->holdingPlan($accountId, function () use ($accountId, $date): DayClose {
            $close = $this->stampClosed($accountId, $date);

            // No plain read comes before the stamp's write, so this read's snapshot is taken after it and holds every timer whose stamp removal ran first.
            if ($date->isSameDay($this->today()) && $this->running($accountId) !== null) {
                throw ValidationException::withMessages(['close' => __('Stop the running timer before closing the day.')]);
            }

            return $close;
        }, 'close');
    }

    public function reopen(int $accountId, CarbonImmutable $date): void
    {
        $this->holdingPlan($accountId, fn () => $this->removeStamp($accountId, $date), 'close');
    }

    /**
     * A timer starting today takes the stamp back without the plan's lock: its entry is written first, so
     * whichever order it and a close land in, a closed day never has a timer running.
     */
    public function reopenForTimer(int $accountId): void
    {
        $this->removeStamp($accountId, $this->today());
    }

    /**
     * The month card: one entry per day of the month holding `$day`, its tile
     * state against today and the time logged on it, plus the closed days
     * against the days that count: every weekday, and a weekend day once it
     * is closed.
     *
     * @return array{month: string, days: list<array{date: string, weekday: int, state: string, minutes: int}>, closed: int, weekdays: int}
     */
    public function month(int $accountId, CarbonImmutable $day): array
    {
        $today = $this->today();
        $start = $day->startOfMonth();
        $end = $day->endOfMonth();
        $closed = DayClose::where('account_id', $accountId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->flip();

        [$from] = LocalCalendar::dayRange($start);
        [, $to] = LocalCalendar::dayRange($end);
        $minutes = TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereNotNull('end_time')
            ->where('start_time', '>=', $from)
            ->where('start_time', '<', $to)
            ->get(['start_time', 'duration_minutes'])
            ->groupBy(fn (TimeEntry $entry) => CarbonImmutable::instance($entry->start_time)->setTimezone(LocalCalendar::timezone())->toDateString())
            ->map(fn (Collection $entries) => (int) $entries->sum('duration_minutes'));

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->dayOfWeekIso,
                'state' => self::tileState($day, $today, $closed->has($day->toDateString())),
                'minutes' => (int) ($minutes[$day->toDateString()] ?? 0),
            ];
        }

        $closedDays = collect($days)->whereIn('state', ['closed', 'stamped']);

        return [
            'month' => $start->toDateString(),
            'days' => $days,
            'closed' => $closedDays->count(),
            'weekdays' => collect($days)->where('weekday', '<=', 5)->count() + $closedDays->where('weekday', '>', 5)->count(),
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
     * A task lives on one day from today on: its picks on other open days, which a pick for `$date` moves there.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DayPick>
     */
    private function plannedElsewhere(int $accountId, CarbonImmutable $date, int $taskId): \Illuminate\Database\Eloquent\Collection
    {
        $closed = DayClose::where('account_id', $accountId)->whereDate('date', '>=', $this->today()->toDateString())->pluck('date')
            ->map(fn ($day) => mb_substr((string) $day, 0, 10));

        return DayPick::query()
            ->live()
            ->where('account_id', $accountId)
            ->where('task_id', $taskId)
            ->whereDate('date', '>=', $this->today()->toDateString())
            ->whereDate('date', '!=', $date->toDateString())
            ->get()
            ->reject(fn (DayPick $pick): bool => $closed->contains($pick->date->toDateString()))
            ->values();
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

    /** Writes before it reads: a day closed already answers the insert with its own row, read after it. */
    private function stampClosed(int $accountId, CarbonImmutable $date): DayClose
    {
        try {
            return DayClose::create(['account_id' => $accountId, 'date' => $date->toDateString(), 'closed_at' => now()]);
        } catch (UniqueConstraintViolationException $e) {
            return DayClose::where('account_id', $accountId)->whereDate('date', $date->toDateString())->first() ?? throw $e;
        }
    }

    /**
     * A plan changes only inside isPlannable's window, and not on a closed
     * day: reopen the day first.
     */
    private function guardPlannable(int $accountId, CarbonImmutable $date, bool $moving = false): void
    {
        // A move may go to any day ahead; a new pick stays within the planning window.
        if ($moving ? $date->toDateString() < $this->today()->toDateString() : ! $this->isPlannable($date)) {
            throw ValidationException::withMessages(['task_id' => __('Picks can be set from today to the end of next week.')]);
        }
        if ($this->isClosed($accountId, $date)) {
            throw ValidationException::withMessages(['task_id' => __('This day is closed. Reopen it to change its plan.')]);
        }
    }

    private function removeStamp(int $accountId, CarbonImmutable $date): void
    {
        DayClose::where('account_id', $accountId)->whereDate('date', $date->toDateString())->delete();
    }

    /**
     * Every plan change and every close or reopen of the account take turns: a move reads the task's picks on
     * other days, so one lock per day would let two moves, or a move and a close, interleave. The turn is the
     * account's `day_plan_locks` row, which comes with the account, held to the commit however long the write takes;
     * reads and writes run in that one transaction. A turn not given within PLAN_LOCK_WAIT_SECONDS, or a missing row,
     * is PlanBusy, with nothing written.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function holdingPlan(int $accountId, callable $callback, string $field = 'task_id'): mixed
    {
        try {
            return DB::transaction(function () use ($accountId, $callback, $field): mixed {
                $lock = DB::table('day_plan_locks')->where('account_id', $accountId);
                $turn = DB::connection()->getDriverName() === 'mariadb'
                    ? $lock->lock('for update wait '.self::PLAN_LOCK_WAIT_SECONDS)->first()
                    : $lock->lockForUpdate()->first();
                if ($turn === null) {
                    throw PlanBusy::on($field);
                }

                return $callback();
            });
        } catch (PDOException $e) {
            // A nested call sees the timeout as DeadlockException, which is no QueryException.
            throw $this->causedByConcurrencyError($e) ? PlanBusy::on($field) : $e;
        }
    }

    /** The task ids of the date's live picks, as a subquery. */
    private function livePicks(int $accountId, CarbonImmutable $date): Builder
    {
        return DayPick::live()->where('account_id', $accountId)->whereDate('date', $date->toDateString())->select('task_id');
    }

    /**
     * The plan's order, the first `$limit` task ids: today's live picks first in their order, then the soonest due day,
     * then work in hand (lane, then name). The due day is read two ways (Task::dueDay): a CRM task's is its stored
     * date, a card's is the local day its instant falls on, which SQL cannot read portably. Inside each kind the day
     * rises with the stored value, so each kind gives its own first `$limit` with bounded reads (dueWindow), the
     * undated ones theirs by lane and name, and the few candidates are sorted together. Rows read: today's picks and a
     * small multiple of `$limit`, whatever the display timezone.
     *
     * @param  Builder<Task>  $query
     * @return list<int>
     */
    private function inPlanOrder(Builder $query, int $accountId, int $limit): array
    {
        $narrow = fn (): Builder => $query->clone()->setEagerLoads([]);
        $todayIds = DayPick::live()->where('account_id', $accountId)->whereDate('date', $this->today()->toDateString())->orderBy('position')->pluck('task_id')->map(fn ($id) => (int) $id)->values();
        $position = $todayIds->flip();

        $leftovers = $narrow()->whereIn('tasks.id', $todayIds)->toBase()->pluck('tasks.id')->map(fn ($id) => (int) $id)->sortBy(fn (int $id): int => $position[$id])->values();
        $wanted = $limit - $leftovers->count();
        if ($wanted <= 0) {
            return $leftovers->take($limit)->all();
        }

        $rest = fn (): Builder => $narrow()->whereNotIn('tasks.id', $todayIds);
        $candidates = collect()
            ->concat($this->dueWindow(
                fn () => $rest()->whereNull('tasks.trello_card_id')->whereNotNull('tasks.due_date'),
                $wanted,
                // a CRM task's day is its stored date
                fn (string $stored): string => mb_substr($stored, 0, 10),
                fn (string $day): array => [CarbonImmutable::parse($day, 'UTC'), CarbonImmutable::parse($day, 'UTC')->addDay()],
            ))
            ->concat($this->dueWindow(
                fn () => $rest()->whereNotNull('tasks.trello_card_id')->whereNotNull('tasks.due_date'),
                $wanted,
                // a card's day is the local one its instant falls on
                fn (string $stored): string => CarbonImmutable::parse($stored, (string) config('app.timezone'))->setTimezone(LocalCalendar::timezone())->toDateString(),
                fn (string $day): array => LocalCalendar::dayRange($day),
            ))
            ->concat($this->inWorkOrder($rest()->whereNull('tasks.due_date'))->limit($wanted)->toBase()->pluck('tasks.id'));

        // One read of the candidates in lane and name order; a stable sort by day keeps that order inside each day.
        $ordered = $this->inWorkOrder($narrow()->whereKey($candidates->map(fn ($id) => (int) $id)->unique()->values()->all())->select(['tasks.id', 'tasks.name', 'tasks.list_name', 'tasks.due_date', 'tasks.trello_card_id']))
            ->get()
            ->sortBy(fn (Task $task): array => [$task->dueDay() === null, $task->dueDay()])
            ->take($wanted)
            ->map(fn (Task $task): int => (int) $task->id);

        return $leftovers->concat($ordered)->values()->all();
    }

    /**
     * The ids of the first `$limit` dated tasks of one kind in the plan's order, where the stored due value rises with
     * the due day: the database orders by it and stops at `$limit`; the days before the last one reached are fewer
     * than `$limit` and read whole, and that last day is read in lane and name order up to the places left.
     *
     * @param  callable(): Builder<Task>  $kind
     * @param  callable(string): string  $dayOf  the due day (as Task::dueDay reads it) of a stored due value
     * @param  callable(string): array{0: CarbonImmutable, 1: CarbonImmutable}  $bounds  the stored range of one due day
     * @return Collection<int, int>
     */
    private function dueWindow(callable $kind, int $limit, callable $dayOf, callable $bounds): Collection
    {
        $reach = $this->inWorkOrder($kind()->orderBy('tasks.due_date'))->limit($limit)->toBase()->get(['tasks.id', 'tasks.due_date']);
        if ($reach->count() < $limit) {
            return $reach->pluck('id')->map(fn ($id) => (int) $id);
        }

        [$from, $to] = $bounds($dayOf((string) $reach->last()->due_date));
        $before = $kind()->where('tasks.due_date', '<', $from)->toBase()->pluck('tasks.id');
        $day = $this->inWorkOrder($kind()->where('tasks.due_date', '>=', $from)->where('tasks.due_date', '<', $to))->limit($limit - $before->count())->toBase()->pluck('tasks.id');

        return $before->concat($day)->map(fn ($id) => (int) $id);
    }

    /**
     * The tasks of `$ids`, loaded by `$query`, in the order of `$ids`.
     *
     * @param  Builder<Task>  $query
     * @param  list<int>  $ids
     * @return Collection<int, Task>
     */
    private function inOrder(Builder $query, array $ids): Collection
    {
        $at = array_flip($ids);

        return $query->whereKey($ids)->get()->sortBy(fn (Task $task): int => $at[$task->id])->values();
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
            'in_progress' => $task->isInProgress(),
            'can_untick' => $task->canUnfinish(),
            'due' => $task->dueDay(),
        ];
    }

    /**
     * Each task row with its logged time on every day, for its details.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withLoggedTime(int $accountId, Collection $rows): Collection
    {
        $logged = LoggedMinutes::byTask($accountId, $rows->pluck('task_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $rows->map(fn (array $row): array => [...$row, 'logged_minutes' => $logged[$row['task_id']] ?? 0]);
    }

    /**
     * The first running timer on each task, the one a row stops.
     *
     * @param  list<int>  $taskIds
     * @return Collection<int, int>
     */
    private function runningIds(int $accountId, array $taskIds): Collection
    {
        return TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereIn('task_id', $taskIds)
            ->whereNull('end_time')
            ->orderBy('start_time')
            ->get(['id', 'task_id'])
            ->unique('task_id')
            ->pluck('id', 'task_id');
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
     * A day's work on tasks: entries started that day, and every timer still running.
     *
     * @return Builder<TimeEntry>
     */
    private function workOn(int $accountId, CarbonImmutable $date, bool $withRunning = true): Builder
    {
        return TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereNotNull('task_id')
            ->where(fn ($q) => $q->when($withRunning, fn ($live) => $live->whereNull('end_time'))->orWhere(fn ($day) => $this->startedOn($day, $date)));
    }

    /**
     * @param  Builder<TimeEntry>|\Illuminate\Database\Query\Builder  $query
     */
    private function startedOn($query, CarbonImmutable $date): void
    {
        [$from, $to] = LocalCalendar::dayRange($date);
        $query->where('start_time', '>=', $from)->where('start_time', '<', $to);
    }
}

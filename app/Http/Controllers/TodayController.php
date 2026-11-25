<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DayPick;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Day\DayPlanner;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home screen. Its colour is the day's state: paper while choosing,
 * vermilion while a timer runs, cobalt once the day is closed.
 */
final class TodayController extends Controller
{
    public function __construct(private readonly DayPlanner $planner) {}

    /**
     * `?view=today` shows the day list even after a close, `?view=summary` the
     * summary before one (the close screen), `?view=timer` the focus view of a
     * running timer. Navigating never writes.
     */
    public function index(Request $request): Response
    {
        $account = $this->accountId();
        $today = $this->planner->today();
        $timers = $this->planner->runningAll($account);
        // ?timer= picks which running timer the focus view shows; the newest by default.
        $running = $timers->firstWhere('id', (int) $request->query('timer')) ?? $timers->first();
        $closed = $this->planner->isClosed($account, $today);

        // A running timer shows inside the day list; its vermilion focus view is one click away.
        $state = match ($request->query('view')) {
            'summary' => DayPlanner::STATE_DONE,
            'today' => DayPlanner::STATE_PAPER,
            'timer' => $running !== null ? DayPlanner::STATE_TIMER : DayPlanner::STATE_PAPER,
            default => $closed && $running === null ? DayPlanner::STATE_DONE : DayPlanner::STATE_PAPER,
        };

        $picks = $this->planner->picks($account, $today);
        $offPlan = $this->planner->offPlan($account, $today);

        return Inertia::render('today/index', [
            'state' => $state,
            'closed' => $closed,
            'date' => $today->toDateString(),
            'dayStart' => LocalCalendar::dayRange($today)[0]->toIso8601String(),
            'maxPicks' => DayPlanner::MAX_PICKS,
            'warnFull' => Auth::user()->prefers('warn_full_day'),
            'picks' => $picks,
            'carried' => $this->planner->carried($account, $today),
            'offPlan' => $offPlan,
            'moved' => $this->planner->moved($account, $today),
            'moveDays' => $this->planner->restOfWeek($account, $today),
            'looseTimers' => $this->planner->looseTimers($timers, $picks, $offPlan)->map(fn (TimeEntry $entry) => $this->timer($entry))->values(),
            'month' => $this->planner->month($account, $today),
            'totals' => $this->planner->totals($account, $today),
            'tomorrowPicks' => $this->planner->picks($account, $today->addDay())->count(),
            'overdue' => $state === DayPlanner::STATE_DONE ? $this->planner->overdue($account) : null,
            'running' => $running === null ? null : $this->timer($running),
            'timers' => $timers->map(fn (TimeEntry $entry) => $this->timer($entry))->values(),
        ]);
    }

    public function plan(): Response
    {
        $account = $this->accountId();
        $tomorrow = $this->planner->today()->addDay();

        return Inertia::render('today/plan', [
            'closed' => $this->planner->isClosed($account, $this->planner->today()),
            'date' => $tomorrow->toDateString(),
            'plannable' => true,
            'maxPicks' => DayPlanner::MAX_PICKS,
            'warnFull' => Auth::user()->prefers('warn_full_day'),
            'picks' => $this->planner->picks($account, $tomorrow),
            'suggestions' => $this->planner->suggestions($account, $tomorrow),
            'month' => $this->planner->month($account, $this->planner->today()),
        ]);
    }

    /**
     * Week: this week's or next week's seven dates, Monday first, each with its picks.
     */
    public function week(Request $request): Response
    {
        $account = $this->accountId();
        $next = $request->boolean('next');
        $monday = $this->planner->today()->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($next ? 1 : 0);

        return Inertia::render('today/week', [
            'today' => $this->planner->today()->toDateString(),
            'closed' => $this->planner->isClosed($account, $this->planner->today()),
            'week' => $next ? 'next' : 'this',
            'maxPicks' => DayPlanner::MAX_PICKS,
            'warnFull' => Auth::user()->prefers('warn_full_day'),
            'month' => $this->planner->month($account, $this->planner->today()),
            'days' => $this->planner->week($account, $monday),
        ]);
    }

    /**
     * The full list of open tasks, where picks are chosen. `?for=tomorrow` picks for tomorrow; `?from=day` says the list
     * was opened from that day's page, so Done returns there.
     */
    public function tasks(Request $request): Response
    {
        $account = $this->accountId();
        $date = $this->listDate($request->query('for'));
        $withHidden = $request->boolean('hidden');
        $hideable = $this->planner->hideableGroups($account);
        $hidden = $hideable->where('hidden', true);

        return Inertia::render('today/tasks', [
            'closed' => $this->planner->isClosed($account, $this->planner->today()),
            'today' => $this->planner->today()->toDateString(),
            'date' => $date->toDateString(),
            'target' => match ($date->toDateString()) {
                $this->planner->today()->toDateString() => 'today',
                $this->planner->today()->addDay()->toDateString() => 'tomorrow',
                default => 'date',
            },
            'lastPlannable' => $this->planner->lastPlannableDay()->toDateString(),
            'maxPicks' => DayPlanner::MAX_PICKS,
            'warnFull' => Auth::user()->prefers('warn_full_day'),
            'pickedCount' => $this->planner->picks($account, $date)->count(),
            'groups' => $this->planner->openList($account, $date, $withHidden),
            'hideable' => $hideable,
            'hidden' => ['groups' => $hidden->count(), 'tasks' => $hidden->sum('count')],
            'showHidden' => $withHidden,
            'back' => $request->query('from') === 'day' ? 'day' : null,
        ]);
    }

    public function toggleHiddenGroup(Request $request): RedirectResponse
    {
        $account = $this->accountId();
        $data = $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('account_id', $account)],
            'list_name' => ['nullable', 'string', 'max:255'],
        ]);

        $this->planner->toggleHidden($account, (int) $data['project_id'], $data['list_name'] ?? null);

        return back();
    }

    public function toggleHiddenClient(Request $request): RedirectResponse
    {
        $account = $this->accountId();
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('account_id', $account)],
        ]);

        $this->planner->toggleClientHidden($account, (int) $data['client_id']);

        return back();
    }

    public function restoreHiddenGroups(): RedirectResponse
    {
        $this->planner->restoreHidden($this->accountId());

        return back();
    }

    public function storePick(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($data['date']) ? CarbonImmutable::parse($data['date'], config('app.display_timezone')) : $this->planner->today();
        $this->planner->pick($this->accountId(), $date, (int) $data['task_id']);

        return back();
    }

    public function destroyPick(DayPick $dayPick): RedirectResponse
    {
        $this->planner->unpick($this->accountId(), $dayPick);

        return back();
    }

    public function dismissCarried(DayPick $dayPick): RedirectResponse
    {
        $this->planner->dismissCarried($this->accountId(), $dayPick);

        return back();
    }

    public function completePick(DayPick $dayPick): RedirectResponse
    {
        if ($dayPick->task !== null) {
            $this->planner->completeTask($dayPick->task);
        }

        return back();
    }

    /** Ticks an off-plan task: same completion path as a pick, never counted toward the plan. */
    public function completeTask(Task $task): RedirectResponse
    {
        $this->planner->completeTask($task);

        return back();
    }

    /** Unticks a pick or an off-plan task after a mistaken tick; its timer stays stopped. */
    public function uncompleteTask(Task $task): RedirectResponse
    {
        $this->planner->uncompleteTask($task);

        return back();
    }

    public function close(Request $request): RedirectResponse
    {
        $date = $this->closingDate($request);
        $this->planner->close($this->accountId(), $date);

        return ($date->isSameDay($this->planner->today()) ? to_route('dashboard') : back())->with('success', __('Day closed.'));
    }

    public function reopen(Request $request): RedirectResponse
    {
        $date = $this->closingDate($request);
        $this->planner->reopen($this->accountId(), $date);

        return $date->isSameDay($this->planner->today()) ? to_route('dashboard') : back();
    }

    /**
     * One day by its date: a past day as its summary, a day ahead as its plan. Today is the home screen.
     */
    public function day(string $date): Response|RedirectResponse
    {
        $account = $this->accountId();
        $today = $this->planner->today();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, LocalCalendar::timezone());
        abort_if($day === false || $day->toDateString() !== $date, 404);
        if ($day->isSameDay($today)) {
            return to_route('dashboard');
        }

        $first = $this->planner->firstDay($account);
        $nav = [
            'date' => $date,
            'prev' => $first !== null && $day->subDay()->toDateString() >= $first->toDateString() ? $day->subDay()->toDateString() : null,
            'next' => $day->addDay()->toDateString(),
            'today' => $today->toDateString(),
        ];

        if ($day->gt($today)) {
            return Inertia::render('today/plan', [
                'closed' => $this->planner->isClosed($account, $today),
                'date' => $date,
                'plannable' => $this->planner->isPlannable($day),
                'maxPicks' => DayPlanner::MAX_PICKS,
                'warnFull' => Auth::user()->prefers('warn_full_day'),
                'picks' => $this->planner->picks($account, $day),
                'suggestions' => $this->planner->suggestions($account, $day),
                'month' => $this->planner->month($account, $today),
                'day' => $nav,
            ]);
        }

        return Inertia::render('today/index', [
            'state' => DayPlanner::STATE_DONE,
            'closed' => $this->planner->isClosed($account, $day),
            'date' => $date,
            'dayStart' => LocalCalendar::dayRange($day)[0]->toIso8601String(),
            'maxPicks' => DayPlanner::MAX_PICKS,
            'warnFull' => Auth::user()->prefers('warn_full_day'),
            'picks' => $this->planner->picks($account, $day),
            'carried' => [],
            'offPlan' => $this->planner->offPlan($account, $day, withRunning: false),
            'moved' => $this->planner->moved($account, $day),
            'moveDays' => [],
            'looseTimers' => [],
            'month' => $this->planner->month($account, $day),
            'totals' => $this->planner->totals($account, $day),
            'tomorrowPicks' => $this->planner->picks($account, $day->addDay())->count(),
            'overdue' => null,
            'running' => null,
            'timers' => [],
            'day' => $nav,
        ]);
    }

    /** Today, or the earlier day a close or reopen names. */
    private function closingDate(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->planner->today()->toDateString()]]);

        return isset($validated['date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], LocalCalendar::timezone()) : $this->planner->today();
    }

    /** `for=tomorrow` or a plannable YYYY-MM-DD; anything else is today. */
    private function listDate(mixed $value): CarbonImmutable
    {
        $today = $this->planner->today();
        if ($value === 'tomorrow') {
            return $today->addDay();
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $today;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalCalendar::timezone());

        // Carbon rolls 2026-10-32 over to 1 November; only a date that reads back the same counts.
        return $date !== null && $date->format('Y-m-d') === $value && $this->planner->isPlannable($date) ? $date : $today;
    }

    /**
     * @return array<string, mixed>
     */
    private function timer(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'started_at' => $entry->start_time?->toIso8601String(),
            'task_id' => $entry->task_id,
            'task' => $entry->task?->name ?? $entry->description,
            'client' => $entry->client?->name,
            'project_id' => $entry->project_id,
            'project' => $entry->project?->name,
        ];
    }

    private function accountId(): int
    {
        return (int) Auth::user()->account_id;
    }
}

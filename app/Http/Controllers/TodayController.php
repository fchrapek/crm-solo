<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DayPick;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Day\DayPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * `?widok=dzis` shows the day list even after a close, `?widok=gotowe` the
     * summary before one (the close screen), `?widok=timer` the focus view of a
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
        $state = match ($request->query('widok')) {
            'gotowe' => DayPlanner::STATE_DONE,
            'dzis' => DayPlanner::STATE_PAPER,
            'timer' => $running !== null ? DayPlanner::STATE_TIMER : DayPlanner::STATE_PAPER,
            default => $closed && $running === null ? DayPlanner::STATE_DONE : DayPlanner::STATE_PAPER,
        };

        $picks = $this->planner->picks($account, $today);
        $offPlan = $this->planner->offPlan($account, $today);

        return Inertia::render('today/index', [
            'state' => $state,
            'closed' => $closed,
            'date' => $today->toDateString(),
            'maxPicks' => DayPlanner::MAX_PICKS,
            'picks' => $picks,
            'offPlan' => $offPlan,
            'looseTimers' => $this->planner->looseTimers($timers, $picks, $offPlan)->map(fn (TimeEntry $entry) => $this->timer($entry))->values(),
            'month' => $this->planner->month($account, $today),
            'totals' => $this->planner->totals($account, $today),
            'tomorrowPicks' => $this->planner->picks($account, $today->addDay())->count(),
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
            'maxPicks' => DayPlanner::MAX_PICKS,
            'picks' => $this->planner->picks($account, $tomorrow),
        ]);
    }

    /**
     * The full list of open tasks, where picks are chosen. `?na=jutro` picks for tomorrow.
     */
    public function tasks(Request $request): Response
    {
        $account = $this->accountId();
        $forTomorrow = $request->query('na') === 'jutro';
        $date = $forTomorrow ? $this->planner->today()->addDay() : $this->planner->today();

        return Inertia::render('today/tasks', [
            'closed' => $this->planner->isClosed($account, $this->planner->today()),
            'date' => $date->toDateString(),
            'target' => $forTomorrow ? 'tomorrow' : 'today',
            'maxPicks' => DayPlanner::MAX_PICKS,
            'pickedCount' => $this->planner->picks($account, $date)->count(),
            'groups' => $this->planner->openList($account, $date),
        ]);
    }

    public function storePick(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = CarbonImmutable::parse($data['date'], config('app.display_timezone'));
        $today = $this->planner->today();
        if ($date->lt($today) || $date->gt($today->addDay())) {
            return back()->withErrors(['task_id' => __('Picks can be set for today or tomorrow only.')]);
        }

        $this->planner->addPick($this->accountId(), $date, (int) $data['task_id']);

        return back();
    }

    public function destroyPick(DayPick $dayPick): RedirectResponse
    {
        $dayPick->delete();

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

    public function close(): RedirectResponse
    {
        $this->planner->close($this->accountId(), $this->planner->today());

        return to_route('dashboard');
    }

    public function reopen(): RedirectResponse
    {
        $this->planner->reopen($this->accountId(), $this->planner->today());

        return to_route('dashboard');
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
            'project' => $entry->project?->name,
        ];
    }

    private function accountId(): int
    {
        return (int) Auth::user()->account_id;
    }
}

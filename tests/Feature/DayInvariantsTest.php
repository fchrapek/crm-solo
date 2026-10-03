<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Day\DayPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The day's two invariants hold when two writes interleave: at most five
 * picks, and never a closed day with a timer running. Each test runs the
 * second call inside the first, at the point a concurrent request would
 * land between the first call's read and its write.
 */
final class DayInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Project $project;

    private DayPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acc']);
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Site']);
        $this->planner = app(DayPlanner::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_two_picks_racing_for_the_last_place_leave_five(): void
    {
        $today = $this->planner->today();
        foreach (range(1, 4) as $n) {
            $this->planner->addPick($this->account->id, $today, $this->task("Pick {$n}")->id);
        }
        $rival = $this->task('Rival');
        $mine = $this->task('Mine');

        // The rival lands after this call has read four picks and before it writes.
        $this->interleaveOnce(DayPick::class, 'creating', fn () => $this->planner->addPick($this->account->id, $today, $rival->id));

        try {
            $this->planner->addPick($this->account->id, $today, $mine->id);
            $this->fail('The sixth pick was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('task_id', $e->errors());
        }

        $this->assertSame(5, DayPick::where('account_id', $this->account->id)->whereDate('date', $today->toDateString())->count());
        $this->assertTrue(DayPick::where('task_id', $rival->id)->exists());
    }

    public function test_a_pick_that_loses_a_free_place_takes_the_next_one(): void
    {
        $today = $this->planner->today();
        $this->planner->addPick($this->account->id, $today, $this->task('First')->id);
        $rival = $this->task('Rival');
        $mine = $this->task('Mine');

        $this->interleaveOnce(DayPick::class, 'creating', fn () => $this->planner->addPick($this->account->id, $today, $rival->id));
        $this->planner->addPick($this->account->id, $today, $mine->id);

        $this->assertSame([1, 2, 3], DayPick::orderBy('slot')->pluck('slot')->all());
    }

    public function test_a_timer_started_while_the_day_closes_keeps_the_day_open(): void
    {
        $task = $this->task('Late work');

        // The timer starts after the close has stamped the day, before it checks for timers.
        $this->interleaveOnce(DayClose::class, 'created', fn () => TimeEntry::startFor($task));

        try {
            $this->planner->close($this->account->id, $this->planner->today());
            $this->fail('The day closed with a timer running.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('close', $e->errors());
        }

        $this->assertSame(1, TimeEntry::whereNull('end_time')->count());
        $this->assertFalse($this->planner->isClosed($this->account->id, $this->planner->today()));
    }

    public function test_a_close_landing_while_a_timer_starts_never_survives_it(): void
    {
        $task = $this->task('Late work');
        $closeError = null;

        // The close runs after the entry is written, before the timer reopens the day.
        $this->interleaveOnce(TimeEntry::class, 'saved', function () use (&$closeError) {
            try {
                $this->planner->close($this->account->id, $this->planner->today());
            } catch (ValidationException $e) {
                $closeError = $e;
            }
        });

        TimeEntry::startFor($task);

        $this->assertNotNull($closeError);
        $this->assertFalse($this->planner->isClosed($this->account->id, $this->planner->today()));
    }

    /**
     * Runs $between once, from inside the first matching model event.
     */
    private function interleaveOnce(string $model, string $event, callable $between): void
    {
        $fired = false;
        $model::$event(function () use (&$fired, $between): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $between();
        });
    }

    private function task(string $name): Task
    {
        return Task::create(['project_id' => $this->project->id, 'name' => $name]);
    }
}

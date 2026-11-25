<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Day\DayPlanner;
use App\Services\Day\PlanBusy;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Concerns\TimesOutThePlanLock;
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
    use TimesOutThePlanLock;

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

    public function test_two_picks_racing_for_the_fifth_place_both_land(): void
    {
        $today = $this->planner->today();
        foreach (range(1, 4) as $n) {
            $this->planner->addPick($this->account->id, $today, $this->task("Pick {$n}")->id);
        }
        $rival = $this->task('Rival');
        $mine = $this->task('Mine');

        // The rival lands after this call has read four picks and before it writes.
        $this->interleaveOnce(DayPick::class, 'creating', fn () => $this->planner->addPick($this->account->id, $today, $rival->id));

        $this->planner->addPick($this->account->id, $today, $mine->id);

        $slots = DayPick::where('account_id', $this->account->id)->whereDate('date', $today->toDateString())->orderBy('slot')->pluck('slot', 'task_id');
        $this->assertSame([1, 2, 3, 4, 5, 6], $slots->values()->all());
        $this->assertSame([5, 6], [$slots[$rival->id], $slots[$mine->id]]);
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

    public function test_a_close_refused_for_a_running_timer_never_commits_its_stamp(): void
    {
        TimeEntry::startFor($this->task('Running'));
        $today = $this->planner->today();
        $stampedAtCommit = [];
        Event::listen(TransactionCommitted::class, function () use (&$stampedAtCommit, $today): void {
            $stampedAtCommit[] = $this->planner->isClosed($this->account->id, $today);
        });

        try {
            $this->planner->close($this->account->id, $today);
            $this->fail('The day closed with a timer running.');
        } catch (ValidationException $e) {
            $this->assertSame(['close' => ['Stop the running timer before closing the day.']], $e->errors());
        }

        $this->assertNotContains(true, $stampedAtCommit, 'no commit carried the stamp the close took back');
        $this->assertFalse($this->planner->isClosed($this->account->id, $today));
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

    public function test_a_move_reads_the_tasks_other_picks_inside_the_turn_it_holds(): void
    {
        $task = $this->task('Moves');
        $today = $this->planner->today();
        $this->planner->pick($this->account->id, $today, $task->id);
        $base = DB::transactionLevel();
        $seen = [];
        DB::listen(function ($query) use (&$seen, $base): void {
            $seen[] = [DB::transactionLevel() > $base, mb_strtolower($query->sql)];
        });

        $this->planner->pick($this->account->id, $today->addDay(), $task->id);

        $turn = collect($seen)->search(fn (array $query): bool => str_contains($query[1], 'from "day_plan_locks"') && $query[0]);
        $this->assertNotFalse($turn, 'the move takes the plan row inside its transaction');
        $picks = collect($seen)->filter(fn (array $query): bool => str_contains($query[1], '"day_picks"'));
        $this->assertNotEmpty($picks);
        $this->assertTrue($picks->every(fn (array $query, int $index): bool => $query[0] && $index > $turn), 'every read and write of the picks runs after the turn, in its transaction');
    }

    public function test_a_turn_not_given_in_time_refuses_the_move_and_writes_nothing(): void
    {
        $task = $this->task('Waits');
        $today = $this->planner->today();
        $this->planner->pick($this->account->id, $today, $task->id);
        $this->planLockTimesOut();

        try {
            $this->planner->pick($this->account->id, $today->addDay(), $task->id);
            $this->fail('The move went through without its turn.');
        } catch (PlanBusy $e) {
            $this->assertSame(['task_id' => ['The plan is being changed. Try again.']], $e->errors());
        }

        $this->assertSame([[$today->toDateString(), null]], DayPick::where('task_id', $task->id)->get()->map(fn (DayPick $pick) => [$pick->date->toDateString(), $pick->moved_to])->all());
    }

    public function test_a_close_without_its_turn_is_refused_on_the_close_and_the_day_stays_open(): void
    {
        $this->planLockTimesOut();

        try {
            $this->planner->close($this->account->id, $this->planner->today());
            $this->fail('The day closed without its turn.');
        } catch (PlanBusy $e) {
            $this->assertArrayHasKey('close', $e->errors());
        }

        $this->assertFalse($this->planner->isClosed($this->account->id, $this->planner->today()));
    }

    public function test_a_plan_change_for_an_account_without_its_plan_row_is_refused_with_nothing_written(): void
    {
        $task = $this->task('No row');
        $today = $this->planner->today();
        DB::table('day_plan_locks')->where('account_id', $this->account->id)->delete();

        try {
            $this->planner->pick($this->account->id, $today, $task->id);
            $this->fail('The pick went through without the plan row.');
        } catch (PlanBusy $e) {
            $this->assertSame(['task_id' => ['The plan is being changed. Try again.']], $e->errors());
        }

        $this->assertSame(0, DayPick::query()->count());
        $this->assertSame(0, DB::table('day_plan_locks')->count(), 'the plan change inserted no row of its own');
    }

    public function test_every_account_has_its_plan_row_from_its_creation_and_the_migration_fills_the_older_ones(): void
    {
        $this->assertTrue(DB::table('day_plan_locks')->where('account_id', $this->account->id)->exists());

        $older = Account::create(['name' => 'Older']);
        DB::table('day_plan_locks')->where('account_id', $older->id)->delete();
        (require database_path('migrations/2026_11_19_130000_fill_day_plan_locks_for_every_account.php'))->up();

        $this->assertSame([$this->account->id, $older->id], DB::table('day_plan_locks')->orderBy('account_id')->pluck('account_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_a_move_whose_source_update_fails_leaves_no_second_pick(): void
    {
        $task = $this->task('Half moved');
        $today = $this->planner->today();
        $this->planner->pick($this->account->id, $today, $task->id);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with(mb_strtolower($query), 'update') && str_contains($query, 'moved_to')) {
                throw new RuntimeException('update failed');
            }
        });

        try {
            $this->planner->pick($this->account->id, $today->addDay(), $task->id);
            $this->fail('The move went through.');
        } catch (RuntimeException $e) {
            $this->assertSame('update failed', $e->getMessage());
        }

        $this->assertSame([$today->toDateString()], DayPick::where('task_id', $task->id)->get()->map(fn (DayPick $pick) => $pick->date->toDateString())->all());
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

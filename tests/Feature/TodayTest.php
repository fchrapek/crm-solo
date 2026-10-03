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
use App\Models\User;
use App\Services\Day\DayPlanner;
use App\Services\Integrations\Trello\CardFinishReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TodayTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        // Wednesday 16 September 2026, 10:00 in Warsaw.
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_home_renders_the_paper_state_with_only_the_days_picks(): void
    {
        $picked = $this->task('Invoice correction');
        $this->task('SEO fixes');
        $this->pick($picked);

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/index', false)
                ->where('state', 'paper')
                ->where('date', '2026-09-16')
                ->where('maxPicks', 5)
                ->has('picks', 1)
                ->where('picks.0.name', 'Invoice correction')
                ->missing('suggestions')
                ->missing('picks.0.days_late')
                ->has('month.days', 30)
            );
    }

    public function test_the_full_list_groups_open_tasks_and_flags_picks(): void
    {
        $picked = $this->task('Picked one');
        $this->task('Still open');
        Task::create(['project_id' => $this->project->id, 'name' => 'Left on Done', 'list_name' => 'Done']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Ticked', 'is_completed' => true]);
        $this->pick($picked);

        $this->actingAs($this->user)->get('/zadania')
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/tasks', false)
                ->where('target', 'today')
                ->where('pickedCount', 1)
                ->has('groups', 1)
                ->where('groups.0.client', 'ACME')
                ->has('groups.0.tasks', 2)
                ->where('groups.0.tasks', fn ($tasks) => collect($tasks)->pluck('picked', 'name')->all() === ['Picked one' => true, 'Still open' => false])
            );

        $this->actingAs($this->user)->get('/zadania?na=jutro')
            ->assertInertia(fn (Assert $page) => $page->where('target', 'tomorrow')->where('date', '2026-09-17'));
    }

    public function test_the_trail_views_open_without_writing_anything(): void
    {
        $this->actingAs($this->user)->get('/?widok=gotowe')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'done')->where('closed', false));
        $this->assertSame(0, DayClose::count());

        $this->actingAs($this->user)->post('/day/close');

        $this->actingAs($this->user)->get('/?widok=dzis')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'paper')->where('closed', true));
        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'done')->where('closed', true));
        $this->assertSame(1, DayClose::count());
    }

    public function test_the_day_list_stays_the_default_while_a_timer_runs(): void
    {
        $task = $this->task('Landing pages');
        $this->pick($task);
        $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start");

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', 'paper')
                ->where('running.task_id', $task->id)
            );

        $this->actingAs($this->user)->get('/?widok=timer')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'timer'));
    }

    public function test_parallel_timers_are_all_listed_and_each_can_be_focused(): void
    {
        $picked = $this->task('Landing pages');
        $other = $this->task('SEO tasks');
        $this->pick($picked);
        $first = $this->actingAs($this->user)->postJson("/tasks/{$picked->id}/time-entries/start")->json('id');
        Carbon::setTestNow(now()->addMinutes(3));
        $this->actingAs($this->user)->postJson("/tasks/{$other->id}/time-entries/start");

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'paper')->has('timers', 2));

        $this->actingAs($this->user)->get("/?widok=timer&timer={$first}")
            ->assertInertia(fn (Assert $page) => $page->where('state', 'timer')->where('running.task', 'Landing pages'));
    }

    public function test_off_plan_lists_todays_unpicked_work_with_its_timers(): void
    {
        $picked = $this->task('Planned');
        $running = $this->task('Unplanned running');
        $logged = $this->task('Unplanned logged');
        $this->pick($picked);
        $this->actingAs($this->user)->postJson("/tasks/{$picked->id}/time-entries/start");
        $this->actingAs($this->user)->postJson("/tasks/{$running->id}/time-entries/start");
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $logged->id,
            'description' => 'Earlier', 'start_time' => now()->subHours(1), 'end_time' => now()->subMinutes(35), 'duration_minutes' => 25,
        ]);

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('offPlan', 2)
                ->where('offPlan', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Unplanned logged', 'Unplanned running'])
                ->where('offPlan', fn ($rows) => collect($rows)->firstWhere('name', 'Unplanned logged')['minutes'] === 25)
                ->where('offPlan', fn ($rows) => collect($rows)->firstWhere('name', 'Unplanned running')['running_id'] !== null)
            );
    }

    public function test_ticking_a_running_task_stops_its_timer_and_completes_it(): void
    {
        $offPlan = $this->task('Off plan');
        $picked = $this->task('Picked');
        $this->pick($picked);
        $this->actingAs($this->user)->postJson("/tasks/{$offPlan->id}/time-entries/start");
        $this->actingAs($this->user)->postJson("/tasks/{$picked->id}/time-entries/start");

        $this->actingAs($this->user)->post("/day/tasks/{$offPlan->id}/complete");
        $this->actingAs($this->user)->post('/day/picks/'.DayPick::firstOrFail()->id.'/complete');

        $this->assertSame(0, TimeEntry::whereNull('end_time')->count());
        $this->assertTrue((bool) $offPlan->fresh()->is_completed);
        $this->assertTrue((bool) $picked->fresh()->is_completed);
    }

    public function test_a_mistaken_tick_can_be_taken_back_without_restarting_the_timer(): void
    {
        $offPlan = $this->task('Off plan');
        $picked = $this->task('Picked');
        $this->pick($picked);
        $this->actingAs($this->user)->postJson("/tasks/{$offPlan->id}/time-entries/start");
        $this->actingAs($this->user)->postJson("/tasks/{$picked->id}/time-entries/start");
        $this->actingAs($this->user)->post("/day/tasks/{$offPlan->id}/complete");
        $this->actingAs($this->user)->post('/day/picks/'.DayPick::firstOrFail()->id.'/complete');

        $this->actingAs($this->user)->delete("/day/tasks/{$offPlan->id}/complete")->assertRedirect();
        $this->actingAs($this->user)->delete("/day/tasks/{$picked->id}/complete")->assertRedirect();

        foreach ([$offPlan, $picked] as $task) {
            $task->refresh();
            $this->assertFalse($task->isDone());
            $this->assertSame('To-Do', $task->list_name);
        }
        $this->assertSame(0, TimeEntry::whereNull('end_time')->count());
        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('picks.0.done', false)
                ->where('offPlan.0.done', false)
            );
    }

    public function test_tick_untick_tick_on_a_recurring_pick_makes_one_successor(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Weekly backup', 'source' => 'manual', 'list_name' => 'To-Do', 'recurrence_period_days' => 7]);
        $this->pick($task);
        $pick = DayPick::firstOrFail();

        $this->actingAs($this->user)->post("/day/picks/{$pick->id}/complete");
        $this->actingAs($this->user)->delete("/day/tasks/{$task->id}/complete");
        $this->actingAs($this->user)->post("/day/picks/{$pick->id}/complete");

        $this->assertTrue($task->fresh()->isDone());
        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
    }

    public function test_a_retick_after_the_successor_was_finished_makes_no_second_one(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Weekly backup', 'source' => 'manual', 'list_name' => 'To-Do', 'recurrence_period_days' => 7]);
        $this->pick($task);
        $pick = DayPick::firstOrFail();

        $this->actingAs($this->user)->post("/day/picks/{$pick->id}/complete");
        $this->actingAs($this->user)->delete("/day/tasks/{$task->id}/complete");
        $successor = Task::where('parent_task_id', $task->id)->sole();
        $this->actingAs($this->user)->post("/day/tasks/{$successor->id}/complete");
        $this->actingAs($this->user)->post("/day/picks/{$pick->id}/complete");

        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
    }

    public function test_untick_on_a_trello_card_clears_only_the_owners_finish(): void
    {
        $board = Project::create(['account_id' => $this->account->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $card = Task::create(['project_id' => $board->id, 'name' => 'Card', 'source' => 'trello', 'trello_card_id' => 'c1', 'list_name' => 'Testing']);
        $this->pick($card);
        $this->actingAs($this->user)->post('/day/picks/'.DayPick::firstOrFail()->id.'/complete');
        $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->where('picks.0.can_untick', true));

        $this->actingAs($this->user)->delete("/day/tasks/{$card->id}/complete");

        $card->refresh();
        $this->assertNull($card->finished_at);
        $this->assertSame('Testing', $card->list_name);
    }

    public function test_a_card_completed_on_its_board_cannot_be_unticked_here(): void
    {
        $board = Project::create(['account_id' => $this->account->id, 'name' => 'Board', 'trello_board_id' => 'b1', 'settings' => ['trello_list_mapping' => ['l_done' => 'Done']]]);
        $card = Task::create(['project_id' => $board->id, 'name' => 'Card', 'source' => 'trello', 'trello_card_id' => 'c1', 'list_name' => 'Testing']);
        $this->pick($card);
        // What a sync does when the card lands on Done: completed, with the finish filled in.
        $card->update(['trello_list_id' => 'l_done']);
        CardFinishReconciler::remap($card);
        $card->refresh();
        $this->assertTrue($card->is_completed);
        $this->assertNotNull($finished = $card->finished_at);

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('picks.0.done', true)->where('picks.0.can_untick', false));

        $this->actingAs($this->user)->delete("/day/tasks/{$card->id}/complete")->assertSessionHasErrors('task_id');
        $this->assertEquals($finished, $card->fresh()->finished_at);
    }

    public function test_another_accounts_task_cannot_be_unticked(): void
    {
        $other = Account::create(['name' => 'Other']);
        $theirs = Task::create(['project_id' => Project::create(['account_id' => $other->id, 'name' => 'Theirs'])->id, 'name' => 'Not yours', 'is_completed' => true, 'finished_at' => now()]);

        $this->actingAs($this->user)->delete("/day/tasks/{$theirs->id}/complete")->assertNotFound();
        $this->assertTrue($theirs->fresh()->isDone());
    }

    public function test_an_off_plan_task_from_another_account_cannot_be_ticked(): void
    {
        $other = Account::create(['name' => 'Other']);
        $theirs = Task::create(['project_id' => Project::create(['account_id' => $other->id, 'name' => 'Theirs'])->id, 'name' => 'Not yours']);

        $this->actingAs($this->user)->post("/day/tasks/{$theirs->id}/complete")->assertNotFound();
        $this->assertFalse((bool) $theirs->fresh()->is_completed);
    }

    public function test_a_reload_after_midnight_moves_the_page_to_the_new_day(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 21:59:00', 'UTC')); // 23:59 in Warsaw
        $this->pick($this->task('Yesterday work'));

        $this->actingAs($this->user)->get('/')
            ->assertInertia(function (Assert $page) {
                $page->where('date', '2026-09-16')->has('picks', 1);

                $this->travelTo(Carbon::parse('2026-09-16 22:01:00', 'UTC')); // 00:01 the next day

                $page->reload(fn (Assert $reloaded) => $reloaded
                    ->where('date', '2026-09-17')
                    ->has('picks', 0)
                    ->where('month.days.16.state', 'today')
                );
            });
    }

    public function test_the_focus_view_falls_back_to_the_list_without_a_timer(): void
    {
        $this->actingAs($this->user)->get('/?widok=timer')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'paper'));
    }

    public function test_a_day_holds_at_most_five_picks(): void
    {
        foreach (range(1, 5) as $i) {
            $this->pick($this->task("T{$i}"));
        }

        $this->actingAs($this->user)
            ->post('/day/picks', ['task_id' => $this->task('Sixth')->id, 'date' => '2026-09-16'])
            ->assertSessionHasErrors('task_id');

        $this->assertSame(5, DayPick::count());
    }

    public function test_the_same_task_cannot_be_picked_twice_for_a_day(): void
    {
        $task = $this->task();
        $this->pick($task);

        $this->actingAs($this->user)
            ->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-09-16'])
            ->assertSessionHasErrors('task_id');
    }

    public function test_a_task_from_another_account_cannot_be_picked(): void
    {
        $other = Account::create(['name' => 'Other']);
        $theirProject = Project::create(['account_id' => $other->id, 'name' => 'Theirs']);
        $theirs = Task::create(['project_id' => $theirProject->id, 'name' => 'Not yours']);

        $this->actingAs($this->user)
            ->post('/day/picks', ['task_id' => $theirs->id, 'date' => '2026-09-16'])
            ->assertSessionHasErrors('task_id');
    }

    public function test_picks_are_limited_to_today_and_tomorrow(): void
    {
        $task = $this->task();

        $this->actingAs($this->user)
            ->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-09-18'])
            ->assertSessionHasErrors('task_id');

        $this->pick($task, '2026-09-17');
        $this->assertTrue(DayPick::whereDate('date', '2026-09-17')->exists());
    }

    public function test_closing_the_day_is_refused_while_a_timer_runs(): void
    {
        $task = $this->task();
        $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start")->assertCreated();

        $this->actingAs($this->user)->post('/day/close')->assertSessionHasErrors('close');
        $this->assertSame(0, DayClose::count());

        $this->actingAs($this->user)->get('/?widok=timer')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'timer')->where('running.task', $task->name));
    }

    public function test_closing_the_day_turns_home_into_the_done_state(): void
    {
        $this->actingAs($this->user)->post('/day/close')->assertRedirect('/');

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'done'));
    }

    public function test_starting_a_timer_reopens_a_closed_day(): void
    {
        $this->actingAs($this->user)->post('/day/close');
        $this->assertSame(1, DayClose::count());

        TimeEntry::create([
            'account_id' => $this->account->id,
            'description' => 'Evening fix',
            'start_time' => now(),
            'end_time' => null,
            'duration_minutes' => 0,
        ]);

        $this->assertSame(0, DayClose::count());
    }

    public function test_completing_a_pick_marks_the_task_done(): void
    {
        $task = $this->task();
        $this->pick($task);
        $pick = DayPick::firstOrFail();

        $this->actingAs($this->user)->post("/day/picks/{$pick->id}/complete");

        $this->assertTrue((bool) $task->fresh()->is_completed);
    }

    public function test_the_plan_page_lists_tomorrows_picks(): void
    {
        $this->pick($this->task('For tomorrow'), '2026-09-17');

        $this->actingAs($this->user)->get('/jutro')
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/plan', false)
                ->where('date', '2026-09-17')
                ->has('picks', 1)
                ->where('picks.0.name', 'For tomorrow')
            );
    }

    public function test_month_card_tiles_follow_the_closes(): void
    {
        foreach (['2026-09-14', '2026-09-13'] as $date) {
            DayClose::create(['account_id' => $this->account->id, 'date' => $date, 'closed_at' => now()]);
        }

        $planner = app(DayPlanner::class);
        $states = collect($planner->month($this->account->id, $planner->today())['days'])->pluck('state', 'date');

        $this->assertSame('closed', $states['2026-09-14']);  // Monday, closed
        $this->assertSame('open', $states['2026-09-15']);    // Tuesday, never closed
        $this->assertSame('rest', $states['2026-09-12']);    // Saturday, not worked
        $this->assertSame('closed', $states['2026-09-13']);  // Sunday, worked and closed
        $this->assertSame('today', $states['2026-09-16']);   // today, still open
        $this->assertSame('future', $states['2026-09-17']);  // Thursday ahead

        $planner->close($this->account->id, $planner->today());
        $states = collect($planner->month($this->account->id, $planner->today())['days'])->pluck('state', 'date');
        $this->assertSame('stamped', $states['2026-09-16']);
    }

    public function test_home_renders_after_a_task_with_time_today_is_deleted(): void
    {
        $task = $this->task('Mis-created');
        $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start");
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $task->id,
            'description' => 'Earlier', 'start_time' => now()->subHour(), 'end_time' => now()->subMinutes(30), 'duration_minutes' => 30,
        ]);

        $task->delete();

        $this->assertSame(0, TimeEntry::whereNotNull('task_id')->count());
        $this->assertSame(2, TimeEntry::where('project_id', $this->project->id)->count());
        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('offPlan', 0)->has('timers', 1)
                ->has('looseTimers', 1)->where('looseTimers.0.task', 'Mis-created')->where('looseTimers.0.project', 'Site'));
    }

    public function test_home_renders_with_a_running_entry_whose_task_id_dangles(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => 999999,
            'description' => 'Orphan', 'start_time' => now()->subMinutes(10),
        ]);

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('offPlan', 0)->where('timers.0.task', 'Orphan')->where('looseTimers.0.id', $entry->id));

        (require database_path('migrations/2026_10_01_090200_detach_time_entries_from_deleted_tasks.php'))->up();
        $this->assertNull($entry->fresh()->task_id);
    }

    public function test_every_running_timer_is_shown_by_exactly_one_row_or_the_loose_list(): void
    {
        $picked = $this->task('Picked');
        $this->pick($picked);
        $offPlan = $this->task('Off plan');
        $start = fn (Task $task) => $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start")->json('id');
        $pickTimer = $start($picked);
        $offPlanTimer = $start($offPlan);
        $second = $start($offPlan);
        $taskless = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id,
            'description' => 'Call', 'start_time' => now()->subMinutes(5),
        ])->id;

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('picks.0.running_id', $pickTimer)
                ->where('offPlan.0.running_id', $offPlanTimer)
                ->where('looseTimers', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$second, $taskless])
            );
        $this->actingAs($this->user)->get('/?widok=gotowe')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'done')->has('looseTimers', 2));
    }

    public function test_bulk_task_delete_detaches_their_time_entries(): void
    {
        $task = $this->task('Bulk');
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $task->id,
            'description' => 'Logged', 'start_time' => now()->subHour(), 'end_time' => now(), 'duration_minutes' => 60,
        ]);

        $this->artisan('tasks:delete', ['--project' => (string) $this->project->id, '--force' => true])->assertSuccessful();

        $this->assertSame(0, Task::count());
        $this->assertNull(TimeEntry::sole()->task_id);
        $this->assertSame($this->project->id, TimeEntry::sole()->project_id);
    }

    private function task(string $name = 'Task'): Task
    {
        return Task::create(['project_id' => $this->project->id, 'name' => $name]);
    }

    private function pick(Task $task, string $date = '2026-09-16'): void
    {
        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id, 'date' => $date]);
    }
}

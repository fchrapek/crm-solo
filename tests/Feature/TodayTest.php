<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\HiddenTaskGroup;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Day\DayPlanner;
use App\Services\Integrations\Trello\CardFinishReconciler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\TimesOutThePlanLock;
use Tests\TestCase;

final class TodayTest extends TestCase
{
    use RefreshDatabase;
    use TimesOutThePlanLock;

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

        $this->actingAs($this->user)->get('/all-tasks')
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/tasks', false)
                ->where('target', 'today')
                ->where('pickedCount', 1)
                ->has('groups', 1)
                ->where('groups.0.client', 'ACME')
                ->has('groups.0.tasks', 2)
                ->where('groups.0.tasks', fn ($tasks) => collect($tasks)->pluck('picked', 'name')->all() === ['Picked one' => true, 'Still open' => false])
            );

        $this->actingAs($this->user)->get('/all-tasks?for=tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('target', 'tomorrow')->where('date', '2026-09-17'));
    }

    public function test_the_full_list_can_pick_for_any_plannable_date_and_falls_back_to_today(): void
    {
        $this->actingAs($this->user)->get('/all-tasks?for=2026-09-25')
            ->assertInertia(fn (Assert $page) => $page->where('target', 'date')->where('date', '2026-09-25')->where('lastPlannable', '2026-09-27'));

        foreach (['2026-09-28', '2026-09-15', 'soon'] as $outside) {
            $this->actingAs($this->user)->get("/all-tasks?for={$outside}")
                ->assertInertia(fn (Assert $page) => $page->where('target', 'today')->where('date', '2026-09-16'));
        }

        // Carbon would roll 31 September over to 1 October, inside the window.
        Carbon::setTestNow(Carbon::parse('2026-09-28 08:00:00', 'UTC'));
        foreach (['2026-09-31', '2026-02-29'] as $overflow) {
            $this->actingAs($this->user)->get("/all-tasks?for={$overflow}")
                ->assertInertia(fn (Assert $page) => $page->where('target', 'today')->where('date', '2026-09-28'));
        }
    }

    public function test_the_trail_views_open_without_writing_anything(): void
    {
        $this->actingAs($this->user)->get('/?view=summary')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'done')->where('closed', false));
        $this->assertSame(0, DayClose::count());

        $this->actingAs($this->user)->post('/day/close');

        $this->actingAs($this->user)->get('/?view=today')
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

        $this->actingAs($this->user)->get('/?view=timer')
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

        $this->actingAs($this->user)->get("/?view=timer&timer={$first}")
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

    public function test_tick_untick_tick_on_a_pick_leaves_it_done(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Weekly backup', 'source' => 'manual', 'list_name' => 'To-Do']);
        $this->pick($task);
        $pick = DayPick::firstOrFail();

        $this->actingAs($this->user)->post("/day/picks/{$pick->id}/complete");
        $this->actingAs($this->user)->delete("/day/tasks/{$task->id}/complete");
        $this->actingAs($this->user)->post("/day/picks/{$pick->id}/complete");

        $this->assertTrue($task->fresh()->isDone());
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
        $this->actingAs($this->user)->get('/?view=timer')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'paper'));
    }

    public function test_picking_a_task_for_another_day_moves_it_and_today_keeps_the_history(): void
    {
        $task = $this->task('Moved on');
        $stays = $this->task('Stays');
        $this->pick($task);
        $this->pick($stays);
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $task->id,
            'description' => 'Earlier', 'start_time' => now()->subHours(1), 'end_time' => now()->subMinutes(35), 'duration_minutes' => 25,
        ]);

        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-09-17'])->assertSessionHasNoErrors();

        $this->assertSame('2026-09-17', DayPick::where('task_id', $task->id)->whereDate('date', '2026-09-16')->first()->moved_to->toDateString());
        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('picks', 1)
                ->where('picks.0.name', 'Stays')
                ->has('offPlan', 0)
                ->has('carried', 0)
                ->has('moved', 1)
                ->where('moved.0.name', 'Moved on')
                ->where('moved.0.minutes', 25)
                ->where('moved.0.moved_to', '2026-09-17'));
    }

    public function test_a_plan_change_while_another_holds_the_plan_answers_with_a_message_not_an_error_page(): void
    {
        $task = $this->task('Busy');
        $this->pick($task);
        $this->planLockTimesOut();

        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-09-17'])
            ->assertRedirect()->assertSessionHasErrors(['task_id' => 'The plan is being changed. Try again.']);
        $this->actingAs($this->user)->post('/day/close')->assertRedirect()->assertSessionHasErrors(['close' => 'The plan is being changed. Try again.']);
        $this->assertSame(1, DayPick::live()->where('task_id', $task->id)->whereDate('date', '2026-09-16')->count());
    }

    public function test_a_move_may_go_past_next_week_and_coming_back_restores_the_days_own_pick(): void
    {
        $task = $this->task('Far ahead');
        $this->pick($task);

        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $this->task('New')->id, 'date' => '2026-10-20'])->assertSessionHasErrors('task_id');
        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-10-20'])->assertSessionHasNoErrors();
        $this->assertSame(1, DayPick::live()->where('task_id', $task->id)->count());

        $this->pick($task);

        $live = DayPick::live()->where('task_id', $task->id)->get();
        $this->assertSame(['2026-09-16'], $live->map(fn (DayPick $pick) => $pick->date->toDateString())->all());
        $this->assertSame('2026-09-16', DayPick::where('task_id', $task->id)->whereDate('date', '2026-10-20')->first()->moved_to->toDateString());
        $this->assertSame(2, DayPick::where('task_id', $task->id)->count());
    }

    public function test_a_task_moved_off_a_day_is_no_longer_picked_there_and_can_be_picked_back(): void
    {
        $task = $this->task('Moves on');
        $this->pick($task, '2026-09-17');
        $this->pick($task, '2026-09-18');

        $this->actingAs($this->user)->get('/all-tasks?for=tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('groups.0.tasks.0.name', 'Moves on')->where('groups.0.tasks.0.picked', false));
        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page->has('picks', 0)->where('suggestions.tasks.0.name', 'Moves on'));

        $this->pick($task, '2026-09-17');

        $this->assertSame(['2026-09-17'], DayPick::live()->where('task_id', $task->id)->get()->map(fn (DayPick $pick) => $pick->date->toDateString())->all());
        $this->actingAs($this->user)->get('/all-tasks?for=tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('groups.0.tasks.0.picked', true));
    }

    public function test_a_pick_moved_off_today_no_longer_leads_the_suggestions_as_left_over(): void
    {
        $task = $this->task('Was today');
        $this->pick($task);
        $this->pick($task, '2026-09-18');

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('suggestions.leftover', 0));
    }

    public function test_a_pick_moved_past_next_week_can_be_removed_there_and_moved_again(): void
    {
        $task = $this->task('Far ahead');
        $this->pick($task);
        $this->pick($task, '2026-10-20');
        $far = DayPick::live()->where('task_id', $task->id)->sole();

        $this->pick($task, '2026-10-21');
        $this->assertSame(['2026-10-21'], DayPick::live()->where('task_id', $task->id)->get()->map(fn (DayPick $pick) => $pick->date->toDateString())->all());
        $this->assertSame('2026-10-21', $far->fresh()->moved_to->toDateString());

        $this->actingAs($this->user)->delete('/day/picks/'.DayPick::live()->where('task_id', $task->id)->sole()->id)->assertSessionHasNoErrors();
        $this->assertSame(0, DayPick::live()->where('task_id', $task->id)->count());
    }

    public function test_a_past_days_pick_still_cannot_be_removed(): void
    {
        $pick = $this->pastPick($this->task('Monday'), '2026-09-14');

        $this->actingAs($this->user)->delete("/day/picks/{$pick->id}")->assertSessionHasErrors('task_id');
        $this->assertNotNull($pick->fresh());
    }

    public function test_a_day_ahead_says_whether_it_takes_new_picks_and_the_full_list_returns_to_it(): void
    {
        $this->actingAs($this->user)->get('/day/2026-09-25')
            ->assertInertia(fn (Assert $page) => $page->component('today/plan')->where('plannable', true));
        $this->actingAs($this->user)->get('/day/2026-10-20')
            ->assertInertia(fn (Assert $page) => $page->component('today/plan')->where('plannable', false));
        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('plannable', true));

        $this->actingAs($this->user)->get('/all-tasks?for=2026-09-25&from=day')
            ->assertInertia(fn (Assert $page) => $page->where('date', '2026-09-25')->where('target', 'date')->where('back', 'day'));
        $this->actingAs($this->user)->get('/all-tasks?for=2026-09-25')
            ->assertInertia(fn (Assert $page) => $page->where('back', null));
        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $this->task('Friday next')->id, 'date' => '2026-09-25'])->assertSessionHasNoErrors();
        $this->assertSame('2026-09-25', DayPick::query()->sole()->date->toDateString());
    }

    public function test_a_past_days_month_tile_keeps_today_as_today(): void
    {
        $this->actingAs($this->user)->get('/day/2026-09-14')
            ->assertInertia(fn (Assert $page) => $page
                ->where('month.month', '2026-09-01')
                ->where('month.days.13.state', 'open')
                ->where('month.days.15.state', 'today')
                ->where('month.days.14.state', 'open'));

        $this->actingAs($this->user)->get('/day/2026-08-12')
            ->assertInertia(fn (Assert $page) => $page
                ->where('month.month', '2026-08-01')
                ->where('month.days.11.state', 'open')
                ->where('month.days.12.state', 'open')
                ->where('month.days', fn ($days) => collect($days)->whereIn('state', ['today', 'stamped', 'future'])->isEmpty()));
    }

    public function test_the_rest_of_the_week_lists_each_day_with_its_picks(): void
    {
        $this->pastPick($this->task('Thursday one'), '2026-09-17');

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('moveDays', [
                    ['date' => '2026-09-17', 'picks' => 1],
                    ['date' => '2026-09-18', 'picks' => 0],
                    ['date' => '2026-09-19', 'picks' => 0],
                    ['date' => '2026-09-20', 'picks' => 0],
                ]));
    }

    public function test_a_past_day_opens_as_its_summary_with_that_days_plan_time_and_neighbours(): void
    {
        $task = $this->task('Monday work');
        $this->pastPick($task, '2026-09-14');
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $task->id, 'description' => 'Work',
            'start_time' => '2026-09-14 08:00:00', 'end_time' => '2026-09-14 09:15:00', 'duration_minutes' => 75,
        ]);
        $this->actingAs($this->user)->postJson("/tasks/{$this->task('Running now')->id}/time-entries/start");

        $this->actingAs($this->user)->get('/day/2026-09-14')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/index')
                ->where('state', 'done')
                ->where('date', '2026-09-14')
                ->where('picks.0.name', 'Monday work')
                ->where('picks.0.minutes', 75)
                ->has('offPlan', 0)
                ->where('totals.logged_minutes', 75)
                ->where('day', ['date' => '2026-09-14', 'prev' => null, 'next' => '2026-09-15', 'today' => '2026-09-16'])
                ->where('month.days.13.minutes', 75));
    }

    public function test_a_day_ahead_opens_as_its_plan_and_today_is_the_home_screen(): void
    {
        $task = $this->task('Friday');
        $this->pastPick($task, '2026-09-18');

        $this->actingAs($this->user)->get('/day/2026-09-18')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/plan')
                ->where('date', '2026-09-18')
                ->where('picks.0.name', 'Friday')
                ->where('day.next', '2026-09-19'));

        $this->actingAs($this->user)->get('/day/2026-09-16')->assertRedirect('/');
        $this->actingAs($this->user)->get('/day/2026-02-30')->assertNotFound();
    }

    public function test_an_earlier_day_can_be_closed_and_reopened_by_its_date_even_with_a_timer_running(): void
    {
        $this->actingAs($this->user)->postJson("/tasks/{$this->task()->id}/time-entries/start");

        $this->actingAs($this->user)->from('/day/2026-09-15')->post('/day/close', ['date' => '2026-09-15'])->assertRedirect('/day/2026-09-15');
        $this->assertTrue(DayClose::whereDate('date', '2026-09-15')->exists());
        $this->assertFalse(DayClose::whereDate('date', '2026-09-16')->exists());

        $this->actingAs($this->user)->from('/day/2026-09-15')->delete('/day/close', ['date' => '2026-09-15'])->assertRedirect('/day/2026-09-15');
        $this->assertFalse(DayClose::whereDate('date', '2026-09-15')->exists());
        $this->actingAs($this->user)->post('/day/close', ['date' => '2026-09-17'])->assertSessionHasErrors('date');
    }

    public function test_a_closed_weekend_day_counts_and_grows_the_months_total(): void
    {
        $before = app(DayPlanner::class)->month($this->account->id, \Carbon\CarbonImmutable::parse('2026-09-16'));
        DayClose::create(['account_id' => $this->account->id, 'date' => '2026-09-12', 'closed_at' => now()]);
        $after = app(DayPlanner::class)->month($this->account->id, \Carbon\CarbonImmutable::parse('2026-09-16'));

        $this->assertSame([0, 22], [$before['closed'], $before['weekdays']]);
        $this->assertSame([1, 23], [$after['closed'], $after['weekdays']]);
    }

    public function test_a_full_day_still_takes_a_sixth_pick(): void
    {
        foreach (range(1, 5) as $i) {
            $this->pick($this->task("T{$i}"));
        }

        $this->actingAs($this->user)
            ->post('/day/picks', ['task_id' => $this->task('Sixth')->id, 'date' => '2026-09-16'])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, DayPick::count());
        $this->assertSame([1, 2, 3, 4, 5, 6], DayPick::orderBy('slot')->pluck('slot')->all());
    }

    public function test_the_full_day_warning_is_on_until_the_user_turns_it_off(): void
    {
        $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->where('maxPicks', 5)->where('warnFull', true));

        $this->actingAs($this->user)->patch('/profile/tasks', ['warn_full_day' => false])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->where('warnFull', false));
        $this->actingAs($this->user)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('task_settings.warn_full_day', false));
    }

    public function test_billed_time_on_tasks_is_the_owners_switch_and_a_member_has_none(): void
    {
        $this->actingAs($this->user)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('task_settings.show_billed_on_tasks', false));
        $this->actingAs($this->user)->patch('/profile/tasks', ['show_billed_on_tasks' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->user->fresh()->prefers('show_billed_on_tasks'));
        $this->assertTrue($this->user->fresh()->prefers('warn_full_day'));

        $member = User::factory()->create(['account_id' => $this->account->id, 'owner' => false]);
        $this->actingAs($member)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('task_settings.show_billed_on_tasks', null));
        $this->actingAs($member)->patch('/profile/tasks', ['show_billed_on_tasks' => true])->assertForbidden();
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

    public function test_picks_reach_from_today_to_the_end_of_next_week(): void
    {
        $task = $this->task();

        foreach (['2026-09-15', '2026-09-28'] as $outside) {
            $this->actingAs($this->user)
                ->post('/day/picks', ['task_id' => $task->id, 'date' => $outside])
                ->assertSessionHasErrors('task_id');
        }

        $this->pick($task, '2026-09-27');
        $this->assertTrue(DayPick::whereDate('date', '2026-09-27')->exists());
    }

    public function test_an_unfinished_pick_from_an_earlier_day_waits_on_today_until_dismissed(): void
    {
        $task = $this->task('From Monday');
        $old = $this->pastPick($task, '2026-09-14');
        $this->pastPick($task, '2026-09-15');

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('carried', 1)
                ->where('carried.0.task_id', $task->id)
                ->where('carried.0.from', '2026-09-15'));

        $shown = DayPick::whereDate('date', '2026-09-15')->value('id');
        $this->actingAs($this->user)->delete("/day/picks/{$shown}/carried")->assertRedirect();

        $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->has('carried', 0));
        $this->assertSame(2, DayPick::where('task_id', $task->id)->whereNotNull('dismissed_at')->count());
        $this->assertNotNull($old->fresh()->dismissed_at);
    }

    public function test_a_pick_on_the_doing_or_testing_list_is_marked_in_progress_until_done(): void
    {
        $doing = $this->task('Doing');
        $doing->update(['list_name' => 'Doing']);
        $todo = $this->task('Todo');
        $finished = $this->task('Finished');
        foreach ([$doing, $todo, $finished] as $task) {
            $this->pick($task);
        }
        $finished->update(['list_name' => 'Testing', 'finished_at' => now()]);

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('picks', fn ($picks) => collect($picks)->pluck('in_progress', 'name')->sortKeys()->all() === ['Doing' => true, 'Finished' => false, 'Todo' => false]));
    }

    public function test_a_carried_task_with_time_today_shows_only_as_off_plan_work(): void
    {
        $task = $this->task('From Monday');
        $this->pastPick($task, '2026-09-15');
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $task->id,
            'description' => 'Earlier', 'start_time' => now()->subHours(1), 'end_time' => now()->subMinutes(35), 'duration_minutes' => 25,
        ]);

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('carried', 0)
                ->has('offPlan', 1)
                ->where('offPlan.0.name', 'From Monday'));
    }

    public function test_a_done_or_repicked_task_is_not_carried(): void
    {
        $done = $this->task('Done since');
        $this->pastPick($done, '2026-09-15');
        $done->update(['finished_at' => now()]);
        $repicked = $this->task('Taken to today');
        $this->pastPick($repicked, '2026-09-15');
        $this->pick($repicked);

        $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->has('carried', 0));
    }

    public function test_a_hidden_trello_list_leaves_the_full_list_until_restored(): void
    {
        $board = Project::create(['account_id' => $this->account->id, 'client_id' => $this->project->client_id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        Task::create(['project_id' => $board->id, 'name' => 'Monthly report', 'list_name' => 'Raport']);
        Task::create(['project_id' => $board->id, 'name' => 'Another report', 'list_name' => 'Raport']);
        Task::create(['project_id' => $board->id, 'name' => 'Real work', 'list_name' => 'Doing']);
        $this->task('Plain work');

        $this->actingAs($this->user)->post('/day/hidden-groups/toggle', ['project_id' => $board->id, 'list_name' => 'Raport'])->assertRedirect();

        $names = fn ($page) => collect($page['props']['groups'])->flatMap(fn ($g) => collect($g['tasks'])->pluck('name'))->sort()->values()->all();
        $page = $this->actingAs($this->user)->get('/all-tasks')->viewData('page');
        $this->assertSame(['Plain work', 'Real work'], $names($page));
        $this->assertSame(['groups' => 1, 'tasks' => 2], $page['props']['hidden']);
        $this->assertContains(['project_id' => $board->id, 'list_name' => 'Raport', 'label' => 'Raport · Board', 'client_id' => $board->client_id, 'client' => 'ACME', 'count' => 2, 'hidden' => true], $page['props']['hideable']);

        $revealed = $this->actingAs($this->user)->get('/all-tasks?hidden=1')->viewData('page');
        $this->assertCount(4, $names($revealed));

        $this->actingAs($this->user)->delete('/day/hidden-groups')->assertRedirect();
        $this->assertCount(4, $names($this->actingAs($this->user)->get('/all-tasks')->viewData('page')));
    }

    public function test_a_hidden_project_stays_out_of_the_plan_suggestions_and_toggles_back(): void
    {
        $this->task('Hidden away');
        $this->actingAs($this->user)->post('/day/hidden-groups/toggle', ['project_id' => $this->project->id]);

        $this->actingAs($this->user)->get('/tomorrow')->assertInertia(fn (Assert $page) => $page->where('suggestions.open', 0));

        $this->actingAs($this->user)->post('/day/hidden-groups/toggle', ['project_id' => $this->project->id]);
        $this->actingAs($this->user)->get('/tomorrow')->assertInertia(fn (Assert $page) => $page->where('suggestions.open', 1));
    }

    public function test_a_group_is_one_row_however_often_it_is_toggled(): void
    {
        $this->task('Hidden away');
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->user)->post('/day/hidden-groups/toggle', ['project_id' => $this->project->id]);
        }

        $this->assertSame(1, HiddenTaskGroup::count());
        $this->expectException(UniqueConstraintViolationException::class);
        HiddenTaskGroup::create(['account_id' => $this->account->id, 'project_id' => $this->project->id, 'group_key' => HiddenTaskGroup::key($this->project->id, null)]);
    }

    public function test_a_done_pick_on_a_day_ahead_can_be_removed_to_free_its_place(): void
    {
        $task = $this->task('Done early');
        $this->pick($task, '2026-09-18');
        $task->update(['finished_at' => now()]);

        $this->actingAs($this->user)->delete('/day/picks/'.DayPick::sole()->id)->assertSessionHasNoErrors();
        $this->assertSame(0, DayPick::count());
    }

    public function test_many_carried_tasks_are_read_in_one_query_shape(): void
    {
        foreach (range(1, 60) as $n) {
            $this->pastPick($this->task("Old {$n}"), '2026-09-1'.($n % 2 === 0 ? '4' : '5'));
        }

        $this->actingAs($this->user)->get('/')->assertInertia(fn (Assert $page) => $page->has('carried', 60));
    }

    public function test_the_week_is_read_in_a_fixed_number_of_queries(): void
    {
        foreach (['2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'] as $day) {
            $task = $this->task("For {$day}");
            $this->pick($task, $day);
            TimeEntry::create(['account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $task->id, 'start_time' => now(), 'end_time' => now()->addMinutes(5), 'duration_minutes' => 5, 'billable' => true]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->user)->get('/week')->assertInertia(fn (Assert $page) => $page->where('days.2.picks.0.minutes', 5));
        $this->assertLessThan(25, count(DB::getQueryLog()));
    }

    public function test_the_upgrade_dismisses_only_picks_before_the_warsaw_day_it_runs_on(): void
    {
        $migration = require database_path('migrations/2026_10_28_100000_add_dismissed_at_to_day_picks_table.php');
        $migration->down();
        // 22:30 UTC on the 15th is already the 16th in Warsaw.
        Carbon::setTestNow(Carbon::parse('2026-09-15 22:30:00', 'UTC'));
        foreach (['2026-09-15', '2026-09-16', '2026-09-17'] as $slot => $day) {
            DB::table('day_picks')->insert(['account_id' => $this->account->id, 'task_id' => $this->task("On {$day}")->id, 'date' => $day, 'slot' => 1, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();

        $this->assertSame(['2026-09-15'], DayPick::whereNotNull('dismissed_at')->get()->map(fn (DayPick $pick) => $pick->date->toDateString())->all());
        $this->assertSame(3, DayPick::count());
    }

    public function test_a_client_hides_every_group_it_has_now_and_shows_them_back_together(): void
    {
        $board = Project::create(['account_id' => $this->account->id, 'client_id' => $this->project->client_id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        Task::create(['project_id' => $board->id, 'name' => 'Monthly report', 'list_name' => 'Raport']);
        Task::create(['project_id' => $board->id, 'name' => 'Real work', 'list_name' => 'Doing']);
        $this->task('Plain work');
        $this->actingAs($this->user)->post('/day/hidden-groups/toggle', ['project_id' => $board->id, 'list_name' => 'Raport']);
        $hidden = fn () => $this->actingAs($this->user)->get('/all-tasks')->viewData('page')['props']['hidden'];

        $this->actingAs($this->user)->post('/day/hidden-groups/client', ['client_id' => $this->project->client_id])->assertRedirect();
        $this->assertSame(['groups' => 3, 'tasks' => 3], $hidden());
        $this->assertSame(3, HiddenTaskGroup::count());

        Project::create(['account_id' => $this->account->id, 'client_id' => $this->project->client_id, 'name' => 'Later']);
        $this->actingAs($this->user)->post('/day/hidden-groups/client', ['client_id' => $this->project->client_id]);
        $this->assertSame(['groups' => 0, 'tasks' => 0], $hidden());
        $this->assertSame(0, HiddenTaskGroup::count());
    }

    public function test_another_accounts_client_cannot_be_hidden(): void
    {
        $other = Account::create(['name' => 'Other']);
        $client = Client::create(['account_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->user)->post('/day/hidden-groups/client', ['client_id' => $client->id])->assertSessionHasErrors('client_id');
        $this->assertDatabaseCount('hidden_task_groups', 0);
    }

    public function test_another_accounts_project_cannot_be_hidden(): void
    {
        $other = Account::create(['name' => 'Other']);
        $project = Project::create(['account_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->user)->post('/day/hidden-groups/toggle', ['project_id' => $project->id])->assertSessionHasErrors('project_id');
        $this->assertDatabaseCount('hidden_task_groups', 0);
    }

    public function test_the_week_shows_seven_dates_with_their_picks_and_which_can_still_be_planned(): void
    {
        $this->pastPick($this->task('Left on Tuesday'), '2026-09-15');
        $this->pick($this->task('For Friday'), '2026-09-18');

        $this->actingAs($this->user)->get('/week')
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/week')
                ->where('week', 'this')
                ->has('days', 7)
                ->where('days.0.date', '2026-09-14')
                ->where('days.1.picks.0.name', 'Left on Tuesday')
                ->where('days.1.plannable', false)
                ->where('days.2.plannable', true)
                ->where('days.4.picks.0.name', 'For Friday')
                ->where('days.6.date', '2026-09-20'));

        $this->actingAs($this->user)->get('/week?next=1')
            ->assertInertia(fn (Assert $page) => $page->where('week', 'next')->where('days.0.date', '2026-09-21')->where('days.6.plannable', true));
    }

    public function test_another_accounts_carried_pick_cannot_be_dismissed(): void
    {
        $other = Account::create(['name' => 'Other']);
        $client = Client::create(['account_id' => $other->id, 'name' => 'Theirs', 'type' => 'business']);
        $project = Project::create(['account_id' => $other->id, 'client_id' => $client->id, 'name' => 'Site']);
        $pick = DayPick::create(['account_id' => $other->id, 'task_id' => Task::create(['project_id' => $project->id, 'name' => 'T'])->id, 'date' => '2026-09-15', 'slot' => 1, 'position' => 1]);

        $this->actingAs($this->user)->delete("/day/picks/{$pick->id}/carried")->assertNotFound();
        $this->assertNull($pick->fresh()->dismissed_at);
    }

    public function test_closing_the_day_is_refused_while_a_timer_runs(): void
    {
        $task = $this->task();
        $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start")->assertCreated();

        $this->actingAs($this->user)->post('/day/close')->assertSessionHasErrors('close');
        $this->assertSame(0, DayClose::count());

        $this->actingAs($this->user)->get('/?view=timer')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'timer')->where('running.task', $task->name));
    }

    public function test_closing_the_day_turns_home_into_the_done_state(): void
    {
        $this->actingAs($this->user)->post('/day/close')->assertRedirect('/');

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'done'));
    }

    public function test_a_close_says_so_once_and_a_refused_one_does_not(): void
    {
        $this->actingAs($this->user)->post('/day/close')->assertSessionHas('success', 'Day closed.');
        $this->actingAs($this->user)->delete('/day/close');

        $task = $this->task();
        $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start")->assertCreated();
        $this->actingAs($this->user)->post('/day/close')->assertSessionMissing('success');
    }

    public function test_every_day_view_knows_whether_today_is_closed(): void
    {
        foreach (['/week', '/tomorrow', '/all-tasks'] as $view) {
            $this->actingAs($this->user)->get($view)->assertInertia(fn (Assert $page) => $page->where('closed', false));
        }

        $this->actingAs($this->user)->post('/day/close');

        foreach (['/?view=today', '/week', '/tomorrow', '/all-tasks'] as $view) {
            $this->actingAs($this->user)->get($view)->assertInertia(fn (Assert $page) => $page->where('closed', true));
        }
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

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('today/plan', false)
                ->where('date', '2026-09-17')
                ->has('picks', 1)
                ->where('picks.0.name', 'For tomorrow')
            );
    }

    public function test_the_plan_page_suggests_todays_leftovers_first_then_the_soonest_due_day(): void
    {
        Task::create(['project_id' => $this->project->id, 'name' => 'No due date']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Due later', 'due_date' => '2026-09-25']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Due soon', 'due_date' => '2026-09-18']);
        $this->pick($this->task('Left over today'));
        $this->pick($this->task('Already tomorrow'), '2026-09-17');
        $done = $this->task('Done today');
        $this->pick($done);
        $done->update(['is_completed' => true]);

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestions.tasks', fn ($tasks) => collect($tasks)->pluck('name')->all() === ['Left over today', 'Due soon', 'Due later'])
                ->where('suggestions.leftover', 1)
                ->where('suggestions.open', 5)
            );
    }

    public function test_the_summary_counts_open_overdue_tasks_and_the_oldest_due_day(): void
    {
        Task::create(['project_id' => $this->project->id, 'name' => 'Oldest', 'due_date' => '2026-09-10']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Yesterday', 'due_date' => '2026-09-15']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Due today', 'due_date' => '2026-09-16']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Finished late', 'due_date' => '2026-09-01', 'is_completed' => true]);

        $this->actingAs($this->user)->get('/?view=summary')
            ->assertInertia(fn (Assert $page) => $page->where('overdue', ['count' => 2, 'oldest' => '2026-09-10']));
    }

    public function test_overdue_tasks_are_counted_only_for_the_summary(): void
    {
        Task::create(['project_id' => $this->project->id, 'name' => 'Oldest', 'due_date' => '2026-09-10']);

        $this->actingAs($this->user)->get('/?view=today')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'paper')->where('overdue', null));
    }

    public function test_the_page_carries_the_display_days_midnight_as_a_utc_instant(): void
    {
        // 00:30 on 17 September in Warsaw is still the 16th in UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-16 22:30:00', 'UTC'));

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', '2026-09-17')
                ->where('dayStart', '2026-09-16T22:00:00+00:00')
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
        $this->actingAs($this->user)->get('/?view=summary')
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

    private function pastPick(Task $task, string $date): DayPick
    {
        $slot = DayPick::whereDate('date', $date)->count() + 1;

        return DayPick::create(['account_id' => $this->account->id, 'task_id' => $task->id, 'date' => $date, 'slot' => $slot, 'position' => $slot]);
    }
}

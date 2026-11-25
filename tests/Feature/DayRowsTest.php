<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DayRowsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        // Wednesday 16 September 2026, 10:00 in Warsaw.
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));

        $account = $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'first_name' => 'F', 'last_name' => 'C', 'email' => 'u@example.com', 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_suggestion_says_why_it_is_offered_left_over_from_today_or_its_due_day(): void
    {
        $leftover = Task::create(['project_id' => $this->project->id, 'name' => 'Left over today']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Due soon', 'due_date' => '2026-09-18']);
        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $leftover->id, 'date' => '2026-09-16'])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestions.tasks.0.name', 'Left over today')
                ->where('suggestions.tasks.0.from_today', true)
                ->where('suggestions.tasks.1.from_today', false)
                ->where('suggestions.tasks.1.due', '2026-09-18')
                ->where('suggestions.tasks.1.client', 'ACME')
                ->where('suggestions.tasks.1.project', 'Site')
            );
    }

    public function test_the_plan_pages_carry_the_month_for_their_side_column(): void
    {
        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('month.month', '2026-09-01')->has('month.days', 30));
        $this->actingAs($this->user)->get('/day/2026-09-24')
            ->assertInertia(fn (Assert $page) => $page->component('today/plan', false)->where('month.month', '2026-09-01'));
    }

    public function test_a_suggestion_carries_the_time_logged_on_its_task_on_every_day_and_a_task_with_none_carries_zero(): void
    {
        $worked = Task::create(['project_id' => $this->project->id, 'name' => 'Worked on', 'due_date' => '2026-09-17']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Untouched', 'due_date' => '2026-09-18']);
        $this->entry($worked, '2026-09-01 08:00:00', 50);
        $this->entry($worked, '2026-09-15 08:00:00', 30);
        $this->entry($worked, '2026-09-16 07:00:00', null);

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestions.tasks.0.name', 'Worked on')
                ->where('suggestions.tasks.0.logged_minutes', 80)
                ->where('suggestions.tasks.1.name', 'Untouched')
                ->where('suggestions.tasks.1.logged_minutes', 0)
            );
    }

    public function test_a_suggestion_knows_the_timer_running_on_its_task_and_an_idle_one_has_none(): void
    {
        $running = Task::create(['project_id' => $this->project->id, 'name' => 'Running', 'due_date' => '2026-09-17']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Idle', 'due_date' => '2026-09-18']);
        $timer = $this->entry($running, '2026-09-16 07:30:00', null);

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestions.tasks.0.running_id', $timer->id)
                ->where('suggestions.tasks.1.running_id', null)
                ->where('timers.0.id', $timer->id)
            );
    }

    public function test_another_accounts_time_and_timers_never_count_on_a_suggestion(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Ours', 'due_date' => '2026-09-17']);
        $other = Account::create(['name' => 'Other']);
        TimeEntry::create([
            'account_id' => $other->id, 'project_id' => $this->project->id, 'task_id' => $task->id, 'description' => 'Not ours',
            'start_time' => '2026-09-15 08:00:00', 'end_time' => '2026-09-15 10:00:00', 'duration_minutes' => 120,
        ]);
        TimeEntry::create([
            'account_id' => $other->id, 'project_id' => $this->project->id, 'task_id' => $task->id, 'description' => 'Not ours either',
            'start_time' => '2026-09-16 07:00:00', 'duration_minutes' => 0,
        ]);

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestions.tasks.0.name', 'Ours')
                ->where('suggestions.tasks.0.logged_minutes', 0)
                ->where('suggestions.tasks.0.running_id', null)
            );
    }

    public function test_todays_picks_carry_their_due_day_and_the_tasks_whole_logged_time_beside_the_days_own(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Picked', 'due_date' => '2026-09-30']);
        $this->entry($task, '2026-09-10 08:00:00', 60);
        $this->entry($task, '2026-09-16 06:00:00', 15);
        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-09-16'])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('picks.0.minutes', 15)
                ->where('picks.0.logged_minutes', 75)
                ->where('picks.0.due', '2026-09-30')
                ->where('picks.0.client', 'ACME')
            );
    }

    private function entry(Task $task, string $start, ?int $minutes): TimeEntry
    {
        return TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'task_id' => $task->id, 'description' => 'Work',
            'start_time' => $start,
            'end_time' => $minutes === null ? null : Carbon::parse($start)->addMinutes($minutes),
            'duration_minutes' => $minutes ?? 0,
        ]);
    }
}

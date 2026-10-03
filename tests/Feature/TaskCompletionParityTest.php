<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\TaskDoneTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Tasks\TaskCompletion;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ticking a task means the same thing on every surface: the day screen, the
 * web task paths, `crm task-done` and MCP `task_done` leave identical state,
 * stop the task's timers and write the same minutes.
 */
final class TaskCompletionParityTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Project $manualProject;

    private Project $trelloProject;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->manualProject = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Private']);
        $this->trelloProject = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_path_finishes_a_manual_task_the_same_way(): void
    {
        $states = collect($this->paths(withListMove: true))->map(function (Closure $tick, string $path) {
            $task = $this->manualTask($path);
            $entry = $this->runningTimer($task);
            $tick($task);

            return $this->snapshot($task, $entry);
        });

        $expected = [
            'is_completed' => true,
            'list_name' => 'Done',
            'agent_lane' => Task::AGENT_LANE_DONE,
            'finished_at' => '2026-09-16 08:00:00',
            'successors' => 1,
            'timer_stopped' => true,
            'minutes' => 2,
        ];
        foreach ($states as $path => $state) {
            $this->assertSame($expected, $state, "Path {$path} diverged.");
        }
    }

    public function test_every_finishing_path_records_a_trello_card_without_touching_what_trello_owns(): void
    {
        $states = collect($this->paths(withListMove: false))->map(function (Closure $tick, string $path) {
            $task = $this->trelloTask($path);
            $entry = $this->runningTimer($task);
            $tick($task);

            return $this->snapshot($task, $entry);
        });

        $expected = [
            'is_completed' => false,
            'list_name' => 'Testing',
            'agent_lane' => Task::AGENT_LANE_DONE,
            'finished_at' => '2026-09-16 08:00:00',
            'successors' => 1,
            'timer_stopped' => true,
            'minutes' => 2,
        ];
        foreach ($states as $path => $state) {
            $this->assertSame($expected, $state, "Path {$path} diverged.");
        }
    }

    public function test_a_recurring_task_passes_its_report_visibility_to_the_next_one(): void
    {
        $reportable = $this->manualTask('Monthly audit');
        $reportable->update(['is_reportable' => true]);
        $hidden = $this->manualTask('Monthly updates');

        $this->artisan('crm:task-done', ['task' => (string) $reportable->id])->assertSuccessful();
        $this->artisan('crm:task-done', ['task' => (string) $hidden->id])->assertSuccessful();

        $this->assertTrue($reportable->fresh()->latestOpenSuccessor()->is_reportable);
        $this->assertFalse($hidden->fresh()->latestOpenSuccessor()->is_reportable);
    }

    public function test_an_agent_task_without_a_lane_lands_in_done_on_every_path(): void
    {
        foreach ($this->paths(withListMove: true) as $path => $tick) {
            $task = $this->manualTask("No lane {$path}");
            $task->update(['agent_lane' => null]);

            $tick($task);

            $this->assertSame(Task::AGENT_LANE_DONE, $task->fresh()->agent_lane, "Path {$path}.");
        }
    }

    public function test_repeating_done_on_a_finished_task_stops_its_timer_and_changes_nothing_else(): void
    {
        foreach (['list move', 'task edit'] as $path) {
            $task = $this->manualTask("Again {$path}");
            app(TaskCompletion::class)->finish($task);
            $entry = $this->runningTimer($task);
            Carbon::setTestNow(now()->addMinutes(3));

            $this->paths(withListMove: true)[$path]($task);

            $this->assertNotNull($entry->fresh()->end_time, "Path {$path}.");
            $this->assertSame('2026-09-16 08:00:00', $task->fresh()->finished_at->toDateTimeString(), "Path {$path}.");
            $this->assertSame(1, Task::where('parent_task_id', $task->id)->count(), "Path {$path}.");
            Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));
        }
    }

    public function test_a_task_created_on_done_is_finished_like_a_tick(): void
    {
        $this->actingAs($this->user)->post("/projects/{$this->manualProject->id}/tasks", ['name' => 'Born done', 'list_name' => 'Done'])->assertSessionHasNoErrors();
        $this->artisan('tasks:create', ['--project' => (string) $this->manualProject->id, '--name' => ['Born done too'], '--list' => 'Done'])->assertSuccessful();

        foreach (['Born done', 'Born done too'] as $name) {
            $task = Task::where('name', $name)->sole();
            $this->assertTrue($task->is_completed, $name);
            $this->assertSame('Done', $task->list_name, $name);
            $this->assertSame('2026-09-16 08:00:00', $task->finished_at?->toDateTimeString(), $name);
        }
    }

    public function test_two_finishes_from_stale_copies_make_one_successor_and_keep_the_first_date(): void
    {
        $task = $this->manualTask('Weekly');
        $first = Task::findOrFail($task->id);
        $second = Task::findOrFail($task->id);

        app(TaskCompletion::class)->finish($first);
        Carbon::setTestNow(now()->addMinutes(5));
        $result = app(TaskCompletion::class)->finish($second);

        $this->assertTrue($result->wasAlreadyDone);
        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
        $this->assertSame('2026-09-16 08:00:00', $task->fresh()->finished_at->toDateTimeString());
    }

    public function test_untick_and_tick_again_reuses_the_waiting_successor(): void
    {
        $task = $this->manualTask('Weekly');
        $completion = app(TaskCompletion::class);

        $completion->finish($task);
        $completion->unfinish($task);
        $completion->finish($task);

        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
    }

    public function test_an_untick_is_refused_when_the_sync_completed_the_card_after_the_check(): void
    {
        $task = $this->trelloTask('Raced');
        app(TaskCompletion::class)->finish($task);
        $this->assertTrue($task->canUnfinish());

        // The sync completes the card between the caller's check and the untick.
        Task::whereKey($task->id)->update(['is_completed' => true, 'list_name' => 'Done']);

        try {
            app(\App\Services\Day\DayPlanner::class)->uncompleteTask($task);
            $this->fail('A board-completed card was reopened.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('task_id', $e->errors());
        }
        $this->assertNotNull($task->fresh()->finished_at);

        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/agent-lane", ['agent_lane' => 'in_progress'])->assertStatus(422);
        $this->assertNotNull($task->fresh()->finished_at);
    }

    public function test_changing_the_interval_between_finishes_still_makes_one_successor(): void
    {
        $task = $this->manualTask('Interval');
        $completion = app(TaskCompletion::class);

        $completion->finish($task);
        $completion->unfinish($task);
        $task->update(['recurrence_period_days' => 14]);
        $completion->finish($task);

        $this->assertSame(1, Task::where('recurrence_predecessor_id', $task->id)->count());
        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
    }

    public function test_a_recurring_chain_keeps_going(): void
    {
        $a = $this->manualTask('Chain');
        $completion = app(TaskCompletion::class);

        $completion->finish($a);
        $b = Task::where('recurrence_predecessor_id', $a->id)->sole();
        $completion->finish($b);
        $c = Task::where('recurrence_predecessor_id', $b->id)->sole();

        $this->assertSame($b->id, $c->parent_task_id);
        $this->assertSame($c->id, $b->fresh()->latestOpenSuccessor()?->id);
        $this->assertFalse($c->is_completed);
    }

    public function test_the_backfill_links_only_unambiguous_successors(): void
    {
        $single = $this->manualTask('Single');
        $double = $this->manualTask('Double');
        $child = fn (Task $parent, int $days) => Task::create([
            'project_id' => $this->manualProject->id, 'name' => $parent->name, 'source' => 'manual',
            'parent_task_id' => $parent->id, 'recurrence_period_days' => $days,
        ]);
        $only = $child($single, 7);
        $child($single, 30);
        $child($double, 7);
        $child($double, 7);

        \Illuminate\Support\Facades\Schema::table('tasks', function ($table): void {
            $table->dropUnique(['recurrence_predecessor_id']);
            $table->dropColumn('recurrence_predecessor_id');
        });
        (require database_path('migrations/2026_10_01_140200_add_recurrence_predecessor_id_to_tasks_table.php'))->up();

        $this->assertSame($single->id, $only->fresh()->recurrence_predecessor_id);
        $this->assertSame(1, Task::whereNotNull('recurrence_predecessor_id')->count());
    }

    public function test_a_historical_successor_is_linked_even_after_the_interval_changed(): void
    {
        $parent = $this->manualTask('Weekly');
        $completion = app(TaskCompletion::class);
        $completion->finish($parent);
        // Before the link existed: the successor carries no predecessor, and the parent moved to 14 days.
        Task::where('parent_task_id', $parent->id)->update(['recurrence_predecessor_id' => null]);
        $parent->update(['recurrence_period_days' => 14]);
        $ambiguous = $this->manualTask('Twice');
        foreach ([1, 2] as $copy) {
            Task::create(['project_id' => $this->manualProject->id, 'name' => 'Twice', 'source' => 'manual', 'parent_task_id' => $ambiguous->id, 'recurrence_period_days' => 7]);
        }

        (require database_path('migrations/2026_10_01_180100_link_recurrence_successors_across_interval_changes.php'))->up();

        $this->assertSame(1, Task::where('recurrence_predecessor_id', $parent->id)->count());
        $this->assertSame(0, Task::where('recurrence_predecessor_id', $ambiguous->id)->count());

        $completion->unfinish($parent);
        $completion->finish($parent);
        $this->assertSame(1, Task::where('parent_task_id', $parent->id)->count());
    }

    public function test_moving_a_trello_card_between_lanes_from_the_crm_stays_refused(): void
    {
        $task = $this->trelloTask('Card');

        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Done'])->assertStatus(422);

        $this->assertNull($task->fresh()->finished_at);
        $this->assertSame('Testing', $task->fresh()->list_name);
    }

    public function test_an_agent_lane_drag_out_of_done_reopens_a_finished_trello_card_and_leaves_its_list(): void
    {
        $task = $this->trelloTask('Card');
        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/agent-lane", ['agent_lane' => 'done'])->assertOk();

        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/agent-lane", ['agent_lane' => 'in_progress'])
            ->assertOk()
            ->assertJson(['agent_lane' => 'in_progress', 'list_name' => 'Testing', 'finished_at' => null]);
    }

    public function test_moving_a_manual_task_out_of_done_unticks_it(): void
    {
        $task = $this->manualTask('Undo me');
        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Done'])->assertOk();

        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Doing'])
            ->assertOk()
            ->assertJson(['list_name' => 'Doing', 'is_completed' => false, 'finished_at' => null]);
        $this->assertSame(Task::AGENT_LANE_BACKLOG, $task->fresh()->agent_lane);
    }

    public function test_the_day_can_close_after_crm_task_done_on_a_running_task(): void
    {
        $task = $this->manualTask('Agent work');
        $this->runningTimer($task);

        $this->artisan('crm:task-done', ['task' => (string) $task->id, '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"stopped_timers":[{"id":');

        $this->actingAs($this->user)->post('/day/close')->assertSessionHasNoErrors();
        $this->assertSame(1, DayClose::count());
    }

    public function test_task_done_reports_the_finish_and_the_card_lane(): void
    {
        $task = $this->trelloTask('Card');

        CrmServer::tool(TaskDoneTool::class, ['task' => $task->id])
            ->assertOk()
            ->assertSee('"finished_at":"2026-09-16T08:00:00+00:00"')
            ->assertSee('"is_completed":false')
            ->assertSee('"card_lane":"Testing"');
    }

    /**
     * @return array<string, Closure(Task): void>
     */
    private function paths(bool $withListMove): array
    {
        $paths = [
            'day pick' => function (Task $task): void {
                $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-09-16'])->assertSessionHasNoErrors();
                $this->actingAs($this->user)->post('/day/picks/'.DayPick::where('task_id', $task->id)->sole()->id.'/complete');
            },
            'day off plan' => fn (Task $task) => $this->actingAs($this->user)->post("/day/tasks/{$task->id}/complete"),
            'agent lane' => fn (Task $task) => $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/agent-lane", ['agent_lane' => 'done'])->assertOk(),
            'crm task-done' => fn (Task $task) => $this->artisan('crm:task-done', ['task' => (string) $task->id])->assertSuccessful(),
            'mcp task_done' => fn (Task $task) => CrmServer::tool(TaskDoneTool::class, ['task' => $task->id])->assertOk(),
        ];

        if ($withListMove) {
            $paths['list move'] = fn (Task $task) => $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Done'])->assertOk();
            $paths['task edit'] = fn (Task $task) => $this->actingAs($this->user)->put("/tasks/{$task->id}", ['name' => $task->name, 'list_name' => 'Done'])->assertSessionHasNoErrors();
        }

        return $paths;
    }

    private function manualTask(string $name): Task
    {
        return Task::create([
            'project_id' => $this->manualProject->id, 'name' => $name, 'source' => 'manual', 'list_name' => 'Doing',
            'cli' => 'claude', 'agent_lane' => Task::AGENT_LANE_IN_PROGRESS, 'recurrence_period_days' => 7,
        ]);
    }

    private function trelloTask(string $name): Task
    {
        return Task::create([
            'project_id' => $this->trelloProject->id, 'name' => $name, 'source' => 'trello', 'trello_card_id' => 'card-'.md5($name),
            'list_name' => 'Testing', 'cli' => 'claude', 'agent_lane' => Task::AGENT_LANE_IN_PROGRESS, 'recurrence_period_days' => 7,
        ]);
    }

    private function runningTimer(Task $task): TimeEntry
    {
        return TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $task->project_id, 'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => now()->subSeconds(90), 'end_time' => null, 'duration_minutes' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Task $task, TimeEntry $entry): array
    {
        $task->refresh();
        $entry->refresh();

        return [
            'is_completed' => (bool) $task->is_completed,
            'list_name' => $task->list_name,
            'agent_lane' => $task->agent_lane,
            'finished_at' => $task->finished_at?->toDateTimeString(),
            'successors' => Task::where('parent_task_id', $task->id)->count(),
            'timer_stopped' => $entry->end_time !== null,
            'minutes' => (int) $entry->duration_minutes,
        ];
    }
}

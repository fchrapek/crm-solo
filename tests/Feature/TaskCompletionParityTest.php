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
            'finished_at' => '2026-09-16 08:00:00',
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
            'finished_at' => '2026-09-16 08:00:00',
            'timer_stopped' => true,
            'minutes' => 2,
        ];
        foreach ($states as $path => $state) {
            $this->assertSame($expected, $state, "Path {$path} diverged.");
        }
    }

    public function test_no_path_makes_a_next_task_from_a_task_that_still_carries_a_recurrence_interval(): void
    {
        $tasks = [
            ...collect($this->paths(withListMove: true))->map(fn (Closure $tick, string $path) => [$tick, $this->manualTask("Manual {$path}")]),
            ...collect($this->paths(withListMove: false))->map(fn (Closure $tick, string $path) => [$tick, $this->trelloTask("Card {$path}")])->values(),
        ];
        Task::query()->update(['recurrence_period_days' => 7]);
        $before = Task::count();

        foreach ($tasks as [$tick, $task]) {
            $tick($task);
            $this->assertNotNull($task->fresh()->finished_at, $task->name);
            $this->assertSame(7, (int) $task->fresh()->recurrence_period_days, $task->name);
        }

        $this->assertSame($before, Task::count());
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

    public function test_two_finishes_from_stale_copies_keep_the_first_date(): void
    {
        $task = $this->manualTask('Weekly');
        $first = Task::findOrFail($task->id);
        $second = Task::findOrFail($task->id);

        app(TaskCompletion::class)->finish($first);
        Carbon::setTestNow(now()->addMinutes(5));
        $result = app(TaskCompletion::class)->finish($second);

        $this->assertTrue($result->wasAlreadyDone);
        $this->assertSame('2026-09-16 08:00:00', $task->fresh()->finished_at->toDateTimeString());
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
    }

    public function test_moving_a_trello_card_between_lanes_from_the_crm_stays_refused(): void
    {
        $task = $this->trelloTask('Card');

        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Done'])->assertStatus(422);

        $this->assertNull($task->fresh()->finished_at);
        $this->assertSame('Testing', $task->fresh()->list_name);
    }

    public function test_moving_a_manual_task_out_of_done_unticks_it(): void
    {
        $task = $this->manualTask('Undo me');
        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Done'])->assertOk();

        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Doing'])
            ->assertOk()
            ->assertJson(['list_name' => 'Doing', 'is_completed' => false, 'finished_at' => null]);
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
        ]);
    }

    private function trelloTask(string $name): Task
    {
        return Task::create([
            'project_id' => $this->trelloProject->id, 'name' => $name, 'source' => 'trello', 'trello_card_id' => 'card-'.md5($name),
            'list_name' => 'Testing',
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
            'finished_at' => $task->finished_at?->toDateTimeString(),
            'timer_stopped' => $entry->end_time !== null,
            'minutes' => (int) $entry->duration_minutes,
        ];
    }
}

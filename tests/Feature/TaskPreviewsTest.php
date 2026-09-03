<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskPreview;
use App\Models\User;
use App\Services\ProjectPreviewLauncherInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class TaskPreviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'T',
            'last_name' => 'U',
            'email' => 'u@example.com',
            'owner' => true,
        ]);

        $this->project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'P',
            'preview_command' => 'ddev start',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_start_preview_returns_preview_not_configured_when_command_is_blank(): void
    {
        $this->project->update(['preview_command' => null]);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'T', 'cli' => Task::CLI_CLAUDE]);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/preview/start")
            ->assertStatus(409)
            ->assertJson(['code' => 'preview_not_configured']);
    }

    public function test_start_preview_invokes_launcher_and_returns_payload(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Hero', 'cli' => Task::CLI_CLAUDE]);

        // Real TaskPreview row used as the launcher's return value — the controller
        // formats it into the response. This keeps the test honest about the JSON shape.
        $preview = TaskPreview::create([
            'task_id' => $task->id,
            'project_id' => $this->project->id,
            'account_id' => $this->account->id,
            'pid' => 54321,
            'port' => 9999,
            'command' => 'ddev start',
            'working_dir' => '/tmp/repo/.worktrees/task-'.$task->id,
            'url' => null,
            'started_at' => now(),
        ]);

        $launcher = Mockery::mock(ProjectPreviewLauncherInterface::class);
        $launcher->shouldReceive('start')
            ->once()
            ->withArgs(fn (Task $arg) => $arg->id === $task->id)
            ->andReturn($preview);
        $this->app->instance(ProjectPreviewLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/preview/start")
            ->assertOk()
            ->assertJson([
                'preview' => [
                    'id' => $preview->id,
                    'port' => 9999,
                    'pid' => 54321,
                    'command' => 'ddev start',
                ],
            ]);
    }

    public function test_start_preview_returns_project_busy_when_another_task_already_running(): void
    {
        $taskA = Task::create(['project_id' => $this->project->id, 'name' => 'A', 'cli' => Task::CLI_CLAUDE]);
        $taskB = Task::create(['project_id' => $this->project->id, 'name' => 'B', 'cli' => Task::CLI_CLAUDE]);
        TaskPreview::create([
            'task_id' => $taskA->id,
            'project_id' => $this->project->id,
            'account_id' => $this->account->id,
            'pid' => 1, 'port' => 1,
            'command' => 'ddev start',
            'working_dir' => '/x',
            'started_at' => now(),
        ]);

        // Launcher should NOT be invoked since mutex check fires first.
        $launcher = Mockery::mock(ProjectPreviewLauncherInterface::class);
        $launcher->shouldNotReceive('start');
        $this->app->instance(ProjectPreviewLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$taskB->id}/preview/start")
            ->assertStatus(409)
            ->assertJson([
                'code' => 'project_busy',
                'conflicting_task' => ['id' => $taskA->id, 'name' => 'A'],
            ]);
    }

    public function test_start_preview_with_force_stops_other_then_starts_this(): void
    {
        $taskA = Task::create(['project_id' => $this->project->id, 'name' => 'A', 'cli' => Task::CLI_CLAUDE]);
        $taskB = Task::create(['project_id' => $this->project->id, 'name' => 'B', 'cli' => Task::CLI_CLAUDE]);
        TaskPreview::create([
            'task_id' => $taskA->id,
            'project_id' => $this->project->id,
            'account_id' => $this->account->id,
            'pid' => 1, 'port' => 1,
            'command' => 'ddev start',
            'working_dir' => '/x',
            'started_at' => now(),
        ]);

        $previewB = TaskPreview::create([
            'task_id' => $taskB->id,
            'project_id' => $this->project->id,
            'account_id' => $this->account->id,
            'pid' => 2, 'port' => 2,
            'command' => 'ddev start',
            'working_dir' => '/y',
            'started_at' => now(),
        ]);

        $launcher = Mockery::mock(ProjectPreviewLauncherInterface::class);
        $launcher->shouldReceive('stop')
            ->once()
            ->withArgs(fn (Task $arg) => $arg->id === $taskA->id);
        $launcher->shouldReceive('start')
            ->once()
            ->withArgs(fn (Task $arg) => $arg->id === $taskB->id)
            ->andReturn($previewB);
        $this->app->instance(ProjectPreviewLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$taskB->id}/preview/start", ['force' => true])
            ->assertOk();
    }

    public function test_stop_preview_calls_launcher_stop(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'T', 'cli' => Task::CLI_CLAUDE]);

        $launcher = Mockery::mock(ProjectPreviewLauncherInterface::class);
        $launcher->shouldReceive('stop')
            ->once()
            ->withArgs(fn (Task $arg) => $arg->id === $task->id);
        $this->app->instance(ProjectPreviewLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->deleteJson("/tasks/{$task->id}/preview/stop")
            ->assertOk()
            ->assertJson(['stopped' => true]);
    }

    public function test_start_preview_requires_authentication(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'T', 'cli' => Task::CLI_CLAUDE]);

        $this->postJson("/tasks/{$task->id}/preview/start")
            ->assertStatus(401);
    }

    public function test_task_running_preview_returns_only_open_row(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'T', 'cli' => Task::CLI_CLAUDE]);
        // A prior closed preview shouldn't leak into the "running" lookup.
        TaskPreview::create([
            'task_id' => $task->id,
            'project_id' => $this->project->id,
            'account_id' => $this->account->id,
            'pid' => 1, 'port' => 1,
            'command' => 'ddev start',
            'working_dir' => '/x',
            'started_at' => now()->subHour(),
            'stopped_at' => now()->subMinute(),
            'stopped_reason' => TaskPreview::STOPPED_NORMAL,
        ]);
        $open = TaskPreview::create([
            'task_id' => $task->id,
            'project_id' => $this->project->id,
            'account_id' => $this->account->id,
            'pid' => 2, 'port' => 2,
            'command' => 'ddev start',
            'working_dir' => '/x',
            'started_at' => now(),
        ]);

        $this->assertSame($open->id, $task->runningPreview()?->id);
    }
}

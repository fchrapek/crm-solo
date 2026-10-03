<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\HostExecDisabledException;
use App\Models\Account;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\User;
use App\Services\DailySessionService;
use App\Services\ProjectPreviewLauncher;
use App\Services\ProjectPreviewLauncherInterface;
use App\Services\TerminalSessionLauncher;
use App\Services\TerminalSessionLauncherInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every surface that runs a process on the host answers to one capability,
 * which DEMO_MODE always turns off.
 */
final class HostExecCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $project = Project::create([
            'account_id' => $account->id,
            'name' => 'P',
            'preview_command' => 'touch /tmp/should-never-exist',
        ]);
        $this->task = Task::create(['project_id' => $project->id, 'name' => 'T', 'cli' => Task::CLI_CLAUDE]);
        $this->repository = Repository::create([
            'project_id' => $project->id,
            'name' => 'repo',
            'local_path' => base_path(),
            'provider' => 'local',
        ]);

        // A launcher reached at all is a failure.
        $this->app->instance(TerminalSessionLauncherInterface::class, Mockery::mock(TerminalSessionLauncherInterface::class));
        $this->app->instance(ProjectPreviewLauncherInterface::class, Mockery::mock(ProjectPreviewLauncherInterface::class));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function switchedOff(): array
    {
        return [
            'capability off' => [['terminal.host_exec' => false]],
            'demo mode with the capability on' => [['terminal.host_exec' => true, 'app.demo' => true]],
        ];
    }

    #[DataProvider('switchedOff')]
    public function test_every_host_exec_endpoint_is_refused(array $config): void
    {
        config($config);

        $task = $this->task->id;
        $repo = $this->repository->id;
        $project = $this->task->project_id;

        $requests = [
            ['postJson', "/tasks/{$task}/start-session", ['base_branch' => 'main']],
            ['getJson', "/tasks/{$task}/session-branches", []],
            ['deleteJson', "/tasks/{$task}/stop-session", []],
            ['postJson', "/tasks/{$task}/resume-session", []],
            ['deleteJson', "/tasks/{$task}/kill-session", []],
            ['postJson', "/tasks/{$task}/preview/start", []],
            ['deleteJson', "/tasks/{$task}/preview/stop", []],
            ['postJson', "/projects/{$project}/repositories", ['name' => 'x', 'local_path' => '/tmp']],
            ['putJson', "/repositories/{$repo}", ['name' => 'x', 'local_path' => '/tmp']],
            ['getJson', "/repositories/{$repo}/branches", []],
            ['postJson', '/daily-session/attach', []],
            ['deleteJson', '/daily-session/attach', []],
        ];

        foreach ($requests as [$method, $uri, $data]) {
            $this->actingAs($this->user)->{$method}($uri, $data)
                ->assertForbidden()
                ->assertJsonPath('code', 'host_exec_disabled');
        }

        $this->assertSame(1, Repository::query()->count());
        $this->assertSame(base_path(), $this->repository->fresh()->local_path);
    }

    public function test_an_inertia_form_post_is_refused_with_a_403(): void
    {
        config(['terminal.host_exec' => false]);

        $this->actingAs($this->user)
            ->post("/projects/{$this->task->project_id}/repositories", ['name' => 'x', 'local_path' => '/tmp'])
            ->assertForbidden();

        $this->assertSame(1, Repository::query()->count());
    }

    public function test_the_services_refuse_on_their_own(): void
    {
        config(['terminal.host_exec' => false]);
        $this->forgetInstances();

        $this->assertFalse(app(TerminalSessionLauncher::class)->tmuxSessionAlive($this->task));
        $this->assertFalse(app(ProjectPreviewLauncher::class)->tmuxSessionAlive($this->task));
        $this->assertFalse(app(DailySessionService::class)->snapshot($this->user->account_id)['available']);

        $this->expectException(HostExecDisabledException::class);
        app(TerminalSessionLauncher::class)->resume($this->task);
    }

    public function test_the_daily_attach_service_refuses_on_its_own(): void
    {
        config(['terminal.host_exec' => false]);
        $this->forgetInstances();

        $this->expectException(HostExecDisabledException::class);
        app(DailySessionService::class)->attach($this->user->account_id);
    }

    public function test_the_task_page_renders_and_tells_the_frontend_the_capability_is_off(): void
    {
        config(['app.demo' => true]);
        $this->forgetInstances();

        $this->actingAs($this->user)
            ->get("/tasks/{$this->task->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('host_exec', false)
                ->where('task.tmux_alive', false));
    }

    public function test_the_capability_is_on_by_default_outside_the_demo(): void
    {
        $this->actingAs($this->user)
            ->get('/zadania')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('host_exec', true));
    }

    private function forgetInstances(): void
    {
        $this->app->forgetInstance(TerminalSessionLauncherInterface::class);
        $this->app->forgetInstance(ProjectPreviewLauncherInterface::class);
    }
}

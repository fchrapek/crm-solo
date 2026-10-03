<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Terminal\RepoBusyException;
use App\Exceptions\Terminal\WorkingTreeDirtyException;
use App\Models\Account;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskSession;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TerminalSessionLauncher;
use App\Services\TerminalSessionLauncherInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class TerminalSessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Project $project;

    /**
     * ttyd PIDs and tmux session names spawned by the integration tests below.
     * Cleaned up in tearDown so a failing assertion can never leak a live
     * process: a leaked ttyd holds the test runner's inherited stdout open, so
     * `php artisan test | tail` blocks forever after PHP itself has exited.
     * That is what made this suite look like it hung for minutes at a time.
     *
     * @var array<int, int>
     */
    private array $spawnedPids = [];

    /** @var array<int, string> */
    private array $tmuxSessions = [];

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
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->spawnedPids as $pid) {
            if ($pid > 0) {
                @posix_kill($pid, SIGTERM);
            }
        }
        $this->spawnedPids = [];

        $tmux = mb_trim((string) shell_exec('command -v tmux 2>/dev/null'));
        foreach ($this->tmuxSessions as $session) {
            if ($tmux !== '') {
                shell_exec(sprintf('%s kill-session -t %s 2>/dev/null', escapeshellarg($tmux), escapeshellarg($session)));
            }
        }
        $this->tmuxSessions = [];

        Mockery::close();
        parent::tearDown();
    }

    public function test_start_session_refuses_when_cli_is_null(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => null,
        ]);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/start-session", ['base_branch' => 'main'])
            ->assertStatus(422);
    }

    public function test_start_session_requires_explicit_base_branch(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);

        // No base_branch passed — forces the user through the picker dialog
        // on the UI side.
        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/start-session")
            ->assertStatus(422);
    }

    public function test_start_session_invokes_launcher_and_advances_lane(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Build hero block',
            'cli' => Task::CLI_CLAUDE,
            'agent_lane' => Task::AGENT_LANE_BACKLOG,
        ]);

        $launcher = Mockery::mock(TerminalSessionLauncherInterface::class);
        $launcher->shouldReceive('launch')
            ->once()
            ->withArgs(fn (Task $arg, string $base) => $arg->id === $task->id && $base === 'main')
            ->andReturn([
                'session_path' => '/tmp/repo/.worktrees/task-'.$task->id,
                'branch_name' => "session/task-{$task->id}",
                'base_branch' => 'main',
                'port' => 7681,
                'pid' => 12345,
                'mode' => Task::SESSION_MODE_WORKTREE,
            ]);
        $this->app->instance(TerminalSessionLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/start-session", ['base_branch' => 'main'])
            ->assertOk()
            ->assertJson([
                'branch_name' => "session/task-{$task->id}",
                'base_branch' => 'main',
                'port' => 7681,
                'pid' => 12345,
            ]);

        $this->assertSame(Task::AGENT_LANE_IN_PROGRESS, $task->fresh()->agent_lane);
    }

    public function test_stop_session_calls_launcher_stop(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
            'session_port' => 7681,
            'session_pid' => 12345,
        ]);

        $launcher = Mockery::mock(TerminalSessionLauncherInterface::class);
        $launcher->shouldReceive('stop')
            ->once()
            ->withArgs(fn (Task $arg) => $arg->id === $task->id);
        $this->app->instance(TerminalSessionLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->deleteJson("/tasks/{$task->id}/stop-session")
            ->assertOk()
            ->assertJson(['stopped' => true]);
    }

    public function test_start_session_passes_custom_base_branch(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CODEX,
        ]);

        $launcher = Mockery::mock(TerminalSessionLauncherInterface::class);
        $launcher->shouldReceive('launch')
            ->once()
            ->withArgs(fn (Task $arg, string $base) => $base === 'preview')
            ->andReturn([
                'session_path' => '/x',
                'branch_name' => 'session/task-1',
                'base_branch' => 'preview',
                'port' => 7682,
                'pid' => 22222,
                'mode' => Task::SESSION_MODE_WORKTREE,
            ]);
        $this->app->instance(TerminalSessionLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/start-session", ['base_branch' => 'preview'])
            ->assertOk();
    }

    public function test_start_session_rejects_invalid_base_branch(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/start-session", ['base_branch' => 'main; rm -rf /'])
            ->assertStatus(422);
    }

    public function test_branches_endpoint_lists_local_branches(): void
    {
        $ttydAvailable = mb_trim((string) shell_exec('command -v git 2>/dev/null')) !== '';
        if (! $ttydAvailable) {
            $this->markTestSkipped('git binary required for branches endpoint test.');
        }
        $repoDir = sys_get_temp_dir().'/crm-solo-branches-test-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        shell_exec(sprintf('cd %s && git init -q -b main && git config user.email t@e.test && git config user.name T && git commit --allow-empty -q -m init && git branch develop && git branch master',
            escapeshellarg($repoDir)));

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);

        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/tasks/{$task->id}/session-branches");

        $response->assertOk();
        $payload = $response->json();
        $this->assertEqualsCanonicalizing(['main', 'master', 'develop'], $payload['branches']);
        $this->assertSame('main', $payload['current_branch']);

        shell_exec('rm -rf '.escapeshellarg($repoDir));
    }

    public function test_branches_endpoint_returns_repository_missing_code(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $this->actingAs($this->user)
            ->getJson("/tasks/{$task->id}/session-branches")
            ->assertStatus(409)
            ->assertJson(['code' => 'repository_missing']);
    }

    public function test_start_session_returns_409_when_no_repository(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);
        // No Repository attached to project — real launcher would throw.
        // Use the real service; the early validation check will surface the error.

        // 409 now carries a structured `code` so the frontend can swap the
        // native alert for the themed RepositoryFormDialog. Other launcher
        // failures (ttyd missing, …) come back as `launcher_error`.
        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/start-session", ['base_branch' => 'main'])
            ->assertStatus(409)
            ->assertJson(['code' => 'repository_missing']);
    }

    public function test_launcher_writes_instructions_naming_the_task_and_its_children_by_id(): void
    {
        $repoDir = sys_get_temp_dir().'/crm-solo-launcher-test-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);

        $parent = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Build landing page',
            'description' => 'Full page context here.',
            'cli' => Task::CLI_CLAUDE,
        ]);
        $hero = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Hero block',
            'description' => 'Hero specs.',
            'parent_task_id' => $parent->id,
        ]);
        Task::create([
            'project_id' => $this->project->id,
            'name' => 'CTA block',
            'description' => 'CTA specs.',
            'parent_task_id' => $parent->id,
        ]);

        // Parent attachment — should land in the brief's "Attachments" section
        // with its absolute path so the CLI can Read it without knowing the
        // CRM storage layout.
        TaskAttachment::create([
            'task_id' => $parent->id,
            'file_path' => 'task-attachments/'.$parent->id.'/spec.pdf',
            'original_name' => 'spec.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'label' => 'Brand spec',
        ]);
        // Child attachment — surfaces under the child's section.
        TaskAttachment::create([
            'task_id' => $hero->id,
            'file_path' => 'task-attachments/'.$hero->id.'/hero.png',
            'original_name' => 'hero.png',
            'mime' => 'image/png',
            'size' => 2048,
        ]);

        $ttydAvailable = mb_trim((string) shell_exec('command -v ttyd 2>/dev/null')) !== '';
        if (! $ttydAvailable) {
            shell_exec('rm -rf '.escapeshellarg($repoDir));
            $this->markTestSkipped('ttyd binary required for launcher integration test.');
        }

        $result = (new TerminalSessionLauncher)->launch($parent->fresh(), 'main');
        $this->spawnedPids[] = $result['pid'] ?? 0;

        $briefPath = $result['session_path'].'/CRM_TASK.md';
        $this->assertFileExists($briefPath);
        $brief = (string) file_get_contents($briefPath);

        // Instructions only: the task id and how to read it; no title, description or file name.
        $this->assertStringContainsString("crm task {$parent->id} --json", $brief);
        $this->assertStringContainsString('## Child tasks', $brief);
        $this->assertStringContainsString("#{$hero->id}", $brief);
        foreach (['Build landing page', 'Full page context here.', 'Hero block', 'Hero specs.', 'CTA block', 'spec.pdf', 'Brand spec', 'hero.png'] as $cardText) {
            $this->assertStringNotContainsString($cardText, $brief);
        }

        // Cleanup: kill the spawned ttyd and remove the test repo.
        if (isset($result['pid']) && $result['pid'] > 0) {
            @posix_kill($result['pid'], SIGTERM);
        }
        shell_exec('rm -rf '.escapeshellarg($repoDir));
    }

    public function test_launcher_falls_back_to_master_when_main_missing(): void
    {
        $ttydAvailable = mb_trim((string) shell_exec('command -v ttyd 2>/dev/null')) !== '';
        if (! $ttydAvailable) {
            $this->markTestSkipped('ttyd binary required for launcher integration test.');
        }
        $repoDir = sys_get_temp_dir().'/crm-solo-master-branch-test-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        // Init the repo on `master` instead of the default `main` to mimic
        // older client repos. The launcher should fall back automatically
        // even though the frontend hardcodes 'main' as the requested base.
        shell_exec(sprintf('cd %s && git init -q -b master && git config user.email t@e.test && git config user.name T && git commit --allow-empty -q -m init',
            escapeshellarg($repoDir)));

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);

        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Legacy master repo task',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $result = (new TerminalSessionLauncher)->launch($task->fresh(), 'main');
        $this->spawnedPids[] = $result['pid'] ?? 0;

        // base_branch should reflect what was actually used, not what was
        // requested — so the frontend can show the correct merge command.
        $this->assertSame('master', $result['base_branch']);

        if (isset($result['pid']) && $result['pid'] > 0) {
            @posix_kill($result['pid'], SIGTERM);
        }
        shell_exec('rm -rf '.escapeshellarg($repoDir));
    }

    public function test_launcher_opens_and_closes_time_entry_around_session(): void
    {
        $ttydAvailable = mb_trim((string) shell_exec('command -v ttyd 2>/dev/null')) !== '';
        if (! $ttydAvailable) {
            $this->markTestSkipped('ttyd binary required for launcher integration test.');
        }
        $repoDir = sys_get_temp_dir().'/crm-solo-time-entry-test-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);

        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Track this session',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $launcher = new TerminalSessionLauncher;
        $result = $launcher->launch($task->fresh(), 'main');

        $entry = TimeEntry::where('task_id', $task->id)
            ->where('source', TimeEntry::SOURCE_TERMINAL_SESSION)
            ->first();
        $this->assertNotNull($entry, 'TimeEntry should be opened on launch');
        $this->assertNull($entry->end_time, 'TimeEntry should still be running');
        $this->assertSame($this->account->id, $entry->account_id);
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame($task->name, $entry->description);

        // Resume (second launch) reuses the same row — no duplicate.
        $launcher->launch($task->fresh(), 'main');
        $this->assertSame(1, TimeEntry::where('task_id', $task->id)->count(),
            'Resume should not open a second TimeEntry');

        $launcher->stop($task->fresh());

        $entry->refresh();
        $this->assertNotNull($entry->end_time, 'TimeEntry should be closed on stop');
        $this->assertGreaterThanOrEqual(0, $entry->duration_minutes);

        if (isset($result['pid']) && $result['pid'] > 0) {
            @posix_kill($result['pid'], SIGTERM);
        }
        shell_exec('rm -rf '.escapeshellarg($repoDir));
    }

    public function test_launcher_creates_and_closes_task_session_history_row(): void
    {
        $ttydAvailable = mb_trim((string) shell_exec('command -v ttyd 2>/dev/null')) !== '';
        if (! $ttydAvailable) {
            $this->markTestSkipped('ttyd binary required for launcher integration test.');
        }
        $repoDir = sys_get_temp_dir().'/crm-solo-session-history-test-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);

        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'History task',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $launcher = new TerminalSessionLauncher;
        $result = $launcher->launch($task->fresh(), 'main');
        $this->spawnedPids[] = $result['pid'] ?? 0;

        // One open history row, populated with branch + worktree metadata and
        // linked to the open TimeEntry. This is the durable record that
        // survives stop() wiping the task.session_* columns.
        $session = TaskSession::where('task_id', $task->id)->first();
        $this->assertNotNull($session);
        $this->assertNull($session->ended_at);
        $this->assertSame('claude', $session->cli);
        $this->assertSame('main', $session->base_branch);
        $this->assertSame("session/task-{$task->id}", $session->branch_name);
        $this->assertSame($result['session_path'], $session->worktree_path);
        $this->assertNotNull($session->time_entry_id);

        // Resume must reuse the open row, not append a duplicate.
        $launcher->launch($task->fresh(), 'main');
        $this->assertSame(1, TaskSession::where('task_id', $task->id)->count(),
            'Resume should not create a second TaskSession row');

        $launcher->stop($task->fresh());

        $session->refresh();
        $this->assertNotNull($session->ended_at, 'Session row should be closed on stop');
        $this->assertSame(TaskSession::ENDED_STOPPED, $session->ended_reason);

        // After stop, a fresh launch creates a NEW history row so the prior
        // session is preserved as visible history.
        $launcher->launch($task->fresh(), 'main');
        $this->assertSame(2, TaskSession::where('task_id', $task->id)->count(),
            'Fresh launch after stop should append a second TaskSession row');

        if (isset($result['pid']) && $result['pid'] > 0) {
            @posix_kill($result['pid'], SIGTERM);
        }
        $latestPid = $task->fresh()->session_pid;
        if ($latestPid !== null && $latestPid > 0) {
            @posix_kill($latestPid, SIGTERM);
        }
        shell_exec('rm -rf '.escapeshellarg($repoDir));
    }

    public function test_stop_leaves_tmux_alive_and_resume_reattaches(): void
    {
        $ttydAvailable = mb_trim((string) shell_exec('command -v ttyd 2>/dev/null')) !== '';
        $tmuxAvailable = mb_trim((string) shell_exec('command -v tmux 2>/dev/null')) !== '';
        if (! $ttydAvailable || ! $tmuxAvailable) {
            $this->markTestSkipped('ttyd + tmux required for resume integration test.');
        }
        $repoDir = sys_get_temp_dir().'/crm-solo-resume-test-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);

        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Resumable session',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $launcher = new TerminalSessionLauncher;
        $launchResult = $launcher->launch($task->fresh(), 'main');
        $this->spawnedPids[] = $launchResult['pid'] ?? 0;

        // launch() only spawns ttyd. ttyd runs the tmux-creating inner command
        // when a WebSocket client attaches, so with no browser attached there is
        // deliberately no tmux session yet — the pane is born on first connect.
        $this->assertFalse($launcher->tmuxSessionAlive($task->fresh()),
            'tmux is created by ttyd on first client connect, not by launch() itself');

        $this->createTmuxSessionAsFirstConnectWould($task->id, $launchResult['session_path']);
        $this->assertTrue($launcher->tmuxSessionAlive($task->fresh()),
            'tmux session should be alive once a client has attached');

        $launcher->stop($task->fresh());

        // The whole point of the redesign: stop() must NOT kill tmux. The
        // session can be resumed with full scrollback intact.
        $this->assertTrue($launcher->tmuxSessionAlive($task->fresh()),
            'tmux session must remain alive after stop() — that is what makes Resume work');
        $this->assertNull($task->fresh()->session_port,
            'session_port still clears on stop so the UI shows Start/Resume affordances');

        $session = TaskSession::where('task_id', $task->id)->orderBy('id')->first();
        $this->assertNotNull($session->ended_at);
        $this->assertSame(TaskSession::ENDED_STOPPED, $session->ended_reason);

        $resumeResult = $launcher->resume($task->fresh());
        $this->spawnedPids[] = $resumeResult['pid'] ?? 0;
        $this->assertGreaterThan(0, $resumeResult['port']);
        $this->assertGreaterThan(0, $resumeResult['pid']);

        // Resume creates a NEW history row — each "I sat down to work" cycle
        // gets its own audit record. Previous row stays closed.
        $this->assertSame(2, TaskSession::where('task_id', $task->id)->count(),
            'Resume should create a fresh TaskSession row, not reopen the closed one');
        $latest = TaskSession::where('task_id', $task->id)->orderByDesc('id')->first();
        $this->assertNull($latest->ended_at);
        $this->assertNotNull($latest->time_entry_id);

        // Now KILL: destructive teardown wipes tmux + closes everything.
        $launcher->kill($task->fresh());
        $this->assertFalse($launcher->tmuxSessionAlive($task->fresh()),
            'kill() must terminate the tmux session');
        $latest->refresh();
        $this->assertNotNull($latest->ended_at);

        if (isset($launchResult['pid']) && $launchResult['pid'] > 0) {
            @posix_kill($launchResult['pid'], SIGTERM);
        }
        if (isset($resumeResult['pid']) && $resumeResult['pid'] > 0) {
            @posix_kill($resumeResult['pid'], SIGTERM);
        }
        shell_exec('rm -rf '.escapeshellarg($repoDir));
    }

    public function test_resume_refuses_when_tmux_is_gone(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Lost session',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $launcher = new TerminalSessionLauncher;

        // No launch ever happened — no tmux session exists. resume() must
        // refuse rather than spawn a half-formed session, since the user's
        // mental model is "reattach to my paused work" not "start fresh".
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No paused tmux session');
        $launcher->resume($task->fresh());
    }

    public function test_resume_endpoint_returns_session_lost_code_when_no_tmux(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $launcher = Mockery::mock(TerminalSessionLauncherInterface::class);
        $launcher->shouldReceive('resume')
            ->once()
            ->andThrow(new \App\Exceptions\Terminal\SessionLostException('No paused tmux session to resume — start a fresh session instead.'));
        $this->app->instance(TerminalSessionLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/resume-session")
            ->assertStatus(409)
            ->assertJson(['code' => 'session_lost']);
    }

    public function test_kill_endpoint_calls_launcher_kill(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $launcher = Mockery::mock(TerminalSessionLauncherInterface::class);
        $launcher->shouldReceive('kill')
            ->once()
            ->withArgs(fn (Task $arg) => $arg->id === $task->id);
        $this->app->instance(TerminalSessionLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->deleteJson("/tasks/{$task->id}/kill-session")
            ->assertOk()
            ->assertJson(['killed' => true]);
    }

    public function test_in_repo_mode_refuses_dirty_working_tree(): void
    {
        $repoDir = sys_get_temp_dir().'/crm-solo-inrepo-dirty-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);
        // Touch an untracked file so `git status --porcelain` returns non-empty.
        file_put_contents($repoDir.'/dirty.txt', 'uncommitted');

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Dirty task',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/uncommitted changes/');

        try {
            (new TerminalSessionLauncher)->launch($task->fresh(), 'main', Task::SESSION_MODE_IN_REPO);
        } finally {
            shell_exec('rm -rf '.escapeshellarg($repoDir));
        }
    }

    public function test_dirty_working_tree_refusal_reads_in_polish_without_long_dashes(): void
    {
        $repoDir = sys_get_temp_dir().'/crm-solo-inrepo-dirty-pl-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);
        file_put_contents($repoDir.'/dirty.txt', 'uncommitted');

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Dirty task',
            'cli' => Task::CLI_CLAUDE,
        ]);

        App::setLocale('pl');

        try {
            (new TerminalSessionLauncher)->launch($task->fresh(), 'main', Task::SESSION_MODE_IN_REPO);
            $this->fail('A dirty working tree must refuse an in-repo session.');
        } catch (WorkingTreeDirtyException $e) {
            $this->assertStringContainsString("Katalog roboczy {$repoDir} ma niezatwierdzone zmiany.", $e->getMessage());
            $this->assertStringContainsString('dirty.txt', $e->getMessage());
            $this->assertStringNotContainsString("\u{2014}", $e->getMessage());
            $this->assertStringNotContainsString("\u{2013}", $e->getMessage());
        } finally {
            shell_exec('rm -rf '.escapeshellarg($repoDir));
        }
    }

    public function test_in_repo_mode_refuses_when_another_in_repo_session_live_on_same_repo(): void
    {
        $repoDir = sys_get_temp_dir().'/crm-solo-inrepo-busy-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);

        // Existing live in-repo session on the same repo, owned by another task.
        $existing = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Already running',
            'cli' => Task::CLI_CLAUDE,
            'session_pid' => posix_getpid(), // a real, alive PID — this PHP process
            'session_port' => 7681,
            'session_mode' => Task::SESSION_MODE_IN_REPO,
        ]);
        $newTask = Task::create([
            'project_id' => $this->project->id,
            'name' => 'New in-repo attempt',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/The repository is busy: an in-repo session is live on task "Already running"/');

        try {
            (new TerminalSessionLauncher)->launch($newTask->fresh(), 'main', Task::SESSION_MODE_IN_REPO);
        } finally {
            // Clear the fake live PID before tearDown so other tests aren't affected.
            $existing->update(['session_pid' => null, 'session_port' => null]);
            shell_exec('rm -rf '.escapeshellarg($repoDir));
        }
    }

    public function test_busy_repo_refusal_reads_in_polish(): void
    {
        $repoDir = sys_get_temp_dir().'/crm-solo-inrepo-busy-pl-'.uniqid('', true);
        mkdir($repoDir, 0o755, true);
        $this->initGitRepo($repoDir);

        Repository::create([
            'project_id' => $this->project->id,
            'name' => 'r',
            'local_path' => $repoDir,
            'provider' => 'local',
        ]);
        $existing = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Already running',
            'cli' => Task::CLI_CLAUDE,
            'session_pid' => posix_getpid(),
            'session_port' => 7681,
            'session_mode' => Task::SESSION_MODE_IN_REPO,
        ]);
        $newTask = Task::create([
            'project_id' => $this->project->id,
            'name' => 'New in-repo attempt',
            'cli' => Task::CLI_CLAUDE,
        ]);

        App::setLocale('pl');

        $this->expectException(RepoBusyException::class);
        $this->expectExceptionMessage("Repozytorium jest zajęte: sesja w repozytorium działa przy zadaniu \"Already running\" (id {$existing->id}). Najpierw ją zatrzymaj.");

        try {
            (new TerminalSessionLauncher)->launch($newTask->fresh(), 'main', Task::SESSION_MODE_IN_REPO);
        } finally {
            $existing->update(['session_pid' => null, 'session_port' => null]);
            shell_exec('rm -rf '.escapeshellarg($repoDir));
        }
    }

    public function test_controller_maps_dirty_to_working_tree_dirty_code(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'T',
            'cli' => Task::CLI_CLAUDE,
        ]);

        $launcher = Mockery::mock(TerminalSessionLauncherInterface::class);
        $launcher->shouldReceive('launch')
            ->once()
            ->andThrow(new WorkingTreeDirtyException("The working tree at /tmp/repo has uncommitted changes.\n\n?? new.txt"));
        $this->app->instance(TerminalSessionLauncherInterface::class, $launcher);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/start-session", ['base_branch' => 'main', 'mode' => 'in_repo'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'working_tree_dirty');
    }

    /**
     * ttyd spawns its child command lazily — only once a WebSocket client
     * attaches to the port. The launcher's inner command is what runs
     * `tmux new-session`, so until a browser opens the terminal iframe there is
     * no tmux session at all. These launcher tests never attach a client, so we
     * seed the session exactly as that first connect would, then exercise the
     * stop/resume/kill semantics against a real tmux server.
     */
    private function createTmuxSessionAsFirstConnectWould(int $taskId, string $cwd): void
    {
        $tmux = mb_trim((string) shell_exec('command -v tmux 2>/dev/null'));
        $session = 'crm-task-'.$taskId;
        shell_exec(sprintf(
            '%s new-session -d -s %s -c %s 2>/dev/null',
            escapeshellarg($tmux),
            escapeshellarg($session),
            escapeshellarg(is_dir($cwd) ? $cwd : sys_get_temp_dir()),
        ));
        $this->tmuxSessions[] = $session;
    }

    private function initGitRepo(string $path): void
    {
        $cmds = [
            'git init -q -b main',
            'git config user.email test@example.com',
            'git config user.name Test',
            'git commit --allow-empty -q -m init',
        ];
        foreach ($cmds as $cmd) {
            shell_exec(sprintf('cd %s && %s', escapeshellarg($path), $cmd));
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TasksControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Account']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'owner' => true,
        ]);
    }

    public function test_task_show_requires_auth(): void
    {
        $project = Project::create(['account_id' => $this->account->id, 'name' => 'P']);
        $task = Task::create(['project_id' => $project->id, 'name' => 'T']);

        $this->get("/tasks/{$task->id}")->assertRedirect('/login');
    }

    public function test_can_view_task_detail(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Test Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Test Project',
            'trello_url' => 'https://trello.com/b/abc',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Fix urgent bug',
            'description' => 'This needs to be fixed ASAP',
            'list_name' => 'In Progress',
            'is_completed' => false,
            'due_date' => now()->addDays(1),
            'labels' => ['urgent', 'follow-up needed'],
            'trello_url' => 'https://trello.com/c/xyz',
        ]);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}")
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('tasks/show')
                ->has('task', fn (Assert $assert) => $assert
                    ->where('name', 'Fix urgent bug')
                    ->where('description', 'This needs to be fixed ASAP')
                    ->where('list_name', 'In Progress')
                    ->where('is_completed', false)
                    ->where('trello_url', 'https://trello.com/c/xyz')
                    ->has('project')
                    ->where('project.name', 'Test Project')
                    ->has('client')
                    ->where('client.name', 'Test Client')
                    ->etc()
                )
            );
    }

    public function test_task_show_surfaces_terminal_session_state(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'P']);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Session task',
            'source' => 'manual',
            'cli' => Task::CLI_CLAUDE,
            'agent_lane' => Task::AGENT_LANE_IN_PROGRESS,
            'session_port' => 7681,
            'session_pid' => 99999,
        ]);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}")
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('tasks/show')
                ->has('task', fn (Assert $assert) => $assert
                    ->where('cli', 'claude')
                    ->where('agent_lane', 'in_progress')
                    ->where('session_port', 7681)
                    ->where('session_branch_name', 'session/task-'.$task->id)
                    ->etc()
                )
            );
    }

    public function test_task_show_surfaces_session_history(): void
    {
        $project = Project::create(['account_id' => $this->account->id, 'name' => 'P']);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Task with history',
            'cli' => Task::CLI_CLAUDE,
        ]);

        // Past session that ended cleanly.
        TaskSession::create([
            'task_id' => $task->id,
            'account_id' => $this->account->id,
            'cli' => 'claude',
            'base_branch' => 'main',
            'branch_name' => 'session/task-'.$task->id,
            'worktree_path' => '/tmp/repo/.worktrees/task-'.$task->id,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHours(2),
            'ended_reason' => TaskSession::ENDED_STOPPED,
        ]);
        // Currently-running session — no ended_at.
        TaskSession::create([
            'task_id' => $task->id,
            'account_id' => $this->account->id,
            'cli' => 'claude',
            'base_branch' => 'develop',
            'branch_name' => 'session/task-'.$task->id,
            'worktree_path' => '/tmp/repo/.worktrees/task-'.$task->id,
            'started_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}")
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('tasks/show')
                ->has('task.sessions', 2)
                // Newest-first ordering — running session is first.
                ->where('task.sessions.0.is_running', true)
                ->where('task.sessions.0.base_branch', 'develop')
                ->where('task.sessions.1.is_running', false)
                ->where('task.sessions.1.ended_reason', 'stopped')
                ->where('task.sessions.1.base_branch', 'main')
            );
    }

    public function test_cannot_view_task_from_other_account(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $project = Project::create(['account_id' => $otherAccount->id, 'name' => 'Other Project']);
        $task = Task::create(['project_id' => $project->id, 'name' => 'Secret Task']);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}")
            ->assertNotFound();
    }

    public function test_task_without_client(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'No Client Project',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Orphan Task',
        ]);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}")
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('tasks/show')
                ->where('task.name', 'Orphan Task')
                ->where('task.client', null)
            );
    }

    public function test_review_routes_are_gone(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Project',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Task',
            'list_name' => 'To Do',
        ]);

        $this->actingAs($this->user)->get('/tasks/review')->assertNotFound();
        $this->actingAs($this->user)->put("/tasks/{$task->id}/approve")->assertNotFound();
        $this->actingAs($this->user)->put("/tasks/{$task->id}/reject")->assertNotFound();
        $this->actingAs($this->user)->put("/tasks/{$task->id}/review-update")->assertNotFound();
    }

    public function test_can_create_manual_task_on_trello_backed_project(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Trello Board',
            'trello_board_id' => 'b_visible',
            'trello_url' => 'https://trello.com/b/b_visible',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/projects/{$project->id}/tasks", [
                'name' => 'Internal note (do not share with client)',
                'description' => 'Internal-only follow-up',
                'list_name' => 'To-Do',
                'priority' => 'medium',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'name' => 'Internal note (do not share with client)',
            'source' => 'manual',
            'trello_card_id' => null,
        ]);
    }

    public function test_can_create_manual_child_task(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Hierarchy Project',
        ]);
        $parent = Task::create([
            'project_id' => $project->id,
            'name' => 'Implement About Us page',
            'source' => 'manual',
            'is_reviewed' => true,
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/tasks", [
                'name' => 'Hero section',
                'description' => 'Build hero',
                'list_name' => 'To-Do',
                'parent_task_id' => $parent->id,
            ])
            ->assertRedirect();

        $child = Task::where('name', 'Hero section')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_task_id);
        $this->assertSame(1, $parent->childTasks()->count());
    }

    public function test_update_rejects_parent_cycles(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Hierarchy Project',
        ]);
        $parent = Task::create([
            'project_id' => $project->id,
            'name' => 'Parent',
            'source' => 'manual',
            'is_reviewed' => true,
        ]);
        $child = Task::create([
            'project_id' => $project->id,
            'name' => 'Child',
            'source' => 'manual',
            'is_reviewed' => true,
            'parent_task_id' => $parent->id,
        ]);

        $this->actingAs($this->user)
            ->put("/tasks/{$parent->id}", [
                'name' => 'Parent',
                'description' => null,
                'list_name' => 'To-Do',
                'parent_task_id' => $child->id,
            ])
            ->assertStatus(422);

        $this->assertNull($parent->fresh()->parent_task_id);
    }

    public function test_deleting_parent_nulls_children_parent_task_id(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Hierarchy Project',
        ]);
        $parent = Task::create([
            'project_id' => $project->id,
            'name' => 'Parent',
            'source' => 'manual',
            'is_reviewed' => true,
        ]);
        $childA = Task::create([
            'project_id' => $project->id,
            'name' => 'Child A',
            'source' => 'manual',
            'is_reviewed' => true,
            'parent_task_id' => $parent->id,
        ]);
        $childB = Task::create([
            'project_id' => $project->id,
            'name' => 'Child B',
            'source' => 'manual',
            'is_reviewed' => true,
            'parent_task_id' => $parent->id,
        ]);
        $childArchived = Task::create([
            'project_id' => $project->id,
            'name' => 'Child Archived',
            'source' => 'manual',
            'is_reviewed' => true,
            'parent_task_id' => $parent->id,
            'archived_at' => now(),
        ]);

        $parent->delete();

        $this->assertNull($childA->fresh()->parent_task_id, 'Active child should have parent reference nulled');
        $this->assertNull($childB->fresh()->parent_task_id, 'Active child should have parent reference nulled');
        $this->assertNull($childArchived->fresh()->parent_task_id, 'Archived child should also have parent reference nulled');
    }

    public function test_child_tasks_relation_excludes_archived(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Hierarchy Project',
        ]);
        $parent = Task::create([
            'project_id' => $project->id,
            'name' => 'Parent',
            'source' => 'manual',
            'is_reviewed' => true,
        ]);
        Task::create([
            'project_id' => $project->id,
            'name' => 'Active Child',
            'source' => 'manual',
            'is_reviewed' => true,
            'parent_task_id' => $parent->id,
        ]);
        Task::create([
            'project_id' => $project->id,
            'name' => 'Archived Child',
            'source' => 'manual',
            'is_reviewed' => true,
            'parent_task_id' => $parent->id,
            'archived_at' => now(),
        ]);

        $this->assertSame(1, $parent->childTasks()->count(), 'childTasks must exclude archived');
        $this->assertSame(2, $parent->allChildTasks()->count(), 'allChildTasks keeps archived');

        // withCount('childTasks') (used by kanban + agent board) must agree.
        $withCount = Task::withCount('childTasks')->find($parent->id);
        $this->assertSame(1, $withCount->child_tasks_count);
    }

    public function test_update_does_not_hang_on_preexisting_cycle(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Cycle Project',
        ]);
        $a = Task::create([
            'project_id' => $project->id,
            'name' => 'A',
            'source' => 'manual',
            'is_reviewed' => true,
        ]);
        $b = Task::create([
            'project_id' => $project->id,
            'name' => 'B',
            'source' => 'manual',
            'is_reviewed' => true,
            'parent_task_id' => $a->id,
        ]);
        // Manually wire a cycle A <-> B by setting A.parent_task_id = B.id
        // via direct DB write, sidestepping the controller's protection. This
        // simulates a row that pre-exists in the DB before the fix shipped
        // (recurring-instance chains can produce one with enough edits).
        $a->forceFill(['parent_task_id' => $b->id])->saveQuietly();

        // Without the visited-set / depth-cap guard, this PUT would walk the
        // A→B→A→B… chain forever and hang the worker until request timeout.
        $start = microtime(true);
        $response = $this->actingAs($this->user)
            ->put("/tasks/{$a->id}", [
                'name' => 'A renamed',
                'description' => null,
                'list_name' => 'To-Do',
                'parent_task_id' => $b->id,
            ]);
        $elapsed = microtime(true) - $start;

        // Whether the response is success or 422 is implementation-detail; the
        // guarantee is "doesn't hang." 5s is generous — a hung loop would exhaust
        // the PHP request timeout (typically 30-60s).
        $this->assertLessThan(5.0, $elapsed, 'Cycle traversal must terminate quickly.');
        $this->assertContains($response->status(), [200, 302, 422]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ProjectsTest extends TestCase
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

    public function test_client_edit_shows_projects_tab(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Test Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Test Project',
            'trello_board_id' => 'board_001',
            'trello_url' => 'https://trello.com/b/abc123',
        ]);

        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_001',
            'name' => 'Task 1',
            'list_name' => 'In Progress',
            'is_completed' => false,
        ]);

        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_002',
            'name' => 'Task 2',
            'list_name' => 'Done',
            'is_completed' => true,
        ]);

        $this->actingAs($this->user)
            ->get("/clients/{$client->id}/edit")
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/edit')
                ->has('projects', 2)
                ->where('projects.0.name', 'General')
                ->where('projects.1.name', 'Test Project')
                ->where('projects.1.tasks_count', 2)
                ->where('projects.1.completed_tasks_count', 1)
                ->has('projects.1.tasks', 2)
            );
    }

    public function test_a_new_client_starts_with_only_its_general_project(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client Without Projects',
        ]);

        $this->actingAs($this->user)
            ->get("/clients/{$client->id}/edit")
            ->assertInertia(fn (Assert $assert) => $assert
                ->has('projects', 1)
                ->where('projects.0.name', 'General')
                ->where('projects.0.is_private', true)
            );
    }

    public function test_project_task_relationships(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Test Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Project A',
        ]);

        Task::create([
            'project_id' => $project->id,
            'name' => 'Do something',
            'list_name' => 'Backlog',
        ]);

        $this->assertSame(1, $project->tasks()->count());
        $this->assertSame(['General', 'Project A'], $client->projects()->orderBy('id')->pluck('name')->all());
        $this->assertSame($this->account->id, $project->account->id);
    }

    public function test_task_casts(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Cast Test',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Test Task',
            'labels' => ['bug', 'urgent'],
            'is_completed' => true,
            'due_date' => '2026-06-01 12:00:00',
        ]);

        $task->refresh();

        $this->assertIsArray($task->labels);
        $this->assertSame(['bug', 'urgent'], $task->labels);
        $this->assertTrue($task->is_completed);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $task->due_date);
    }

    public function test_creating_client_via_controller_auto_creates_general_project(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/clients', [
                'type' => 'business',
                'name' => 'Acme Co',
            ]);

        $client = Client::where('name', 'Acme Co')->firstOrFail();
        $response->assertRedirect("/clients/{$client->id}/edit");
        $this->assertSame(1, $client->projects()->whereNull('trello_board_id')->where('name', 'General')->count());
    }

    public function test_store_private_project_via_controller(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $this->actingAs($this->user)
            ->post("/clients/{$client->id}/projects", [
                'name' => 'Maintenance',
                'description' => 'Ongoing site maintenance',
            ])
            ->assertRedirect();

        $project = Project::where('client_id', $client->id)->where('name', 'Maintenance')->firstOrFail();
        $this->assertNull($project->trello_board_id);
        $this->assertSame('Ongoing site maintenance', $project->description);
        $this->assertSame($this->account->id, $project->account_id);
    }

    public function test_update_renames_project_and_persists_description(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Original',
            'description' => 'Old description',
        ]);

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}", [
                'name' => 'Renamed',
                'description' => 'New description',
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('Renamed', $project->name);
        $this->assertSame('New description', $project->description);
    }

    public function test_update_requires_account_ownership(): void
    {
        $other = Account::create(['name' => 'Other']);
        $otherClient = Client::create(['account_id' => $other->id, 'name' => 'Other Client']);
        $project = Project::create([
            'account_id' => $other->id,
            'client_id' => $otherClient->id,
            'name' => 'Other',
        ]);

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}", ['name' => 'Hijack'])
            ->assertForbidden();

        $this->assertSame('Other', $project->fresh()->name);
    }

    public function test_destroy_refuses_trello_backed_projects(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Trello Project',
            'trello_board_id' => 'board_xyz',
        ]);

        $this->actingAs($this->user)
            ->delete("/projects/{$project->id}")
            ->assertStatus(422);

        $this->assertNotNull(Project::find($project->id));
    }

    public function test_destroy_private_project_cascades_tasks(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Private',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Manual task',
            'source' => 'manual',
            'is_reviewed' => true,
        ]);

        $this->actingAs($this->user)
            ->delete("/projects/{$project->id}")
            ->assertRedirect();

        $this->assertNull(Project::find($project->id));
        $this->assertNull(Task::find($task->id));
    }

    public function test_update_list_name_moves_manual_task_between_lanes(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Move me',
            'source' => 'manual',
            'list_name' => 'Backlog',
            'is_completed' => false,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Doing'])
            ->assertOk()
            ->assertJson(['list_name' => 'Doing', 'is_completed' => false]);

        $task->refresh();
        $this->assertSame('Doing', $task->list_name);
        $this->assertFalse($task->is_completed);
    }

    public function test_update_list_name_allows_email_source_task_to_move(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'From email',
            'source' => 'email',
            'list_name' => 'To-Do',
            'is_completed' => false,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Doing'])
            ->assertOk()
            ->assertJson(['list_name' => 'Doing']);

        $this->assertSame('Doing', $task->fresh()->list_name);
    }

    public function test_update_list_name_rejects_trello_source_task(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
            'trello_board_id' => 'board-x',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Synced from Trello',
            'source' => 'trello',
            'trello_card_id' => 'card-x',
            'list_name' => 'Backlog',
            'is_completed' => false,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Doing'])
            ->assertStatus(422);

        $this->assertSame('Backlog', $task->fresh()->list_name);
    }

    public function test_update_list_name_to_done_completes_manual_task(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Finish me',
            'source' => 'manual',
            'list_name' => 'Doing',
            'is_completed' => false,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Done'])
            ->assertOk()
            ->assertJson(['list_name' => 'Done', 'is_completed' => true]);

        $this->assertTrue($task->fresh()->is_completed);
    }

    public function test_archive_marks_manual_task_archived(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Old task',
            'source' => 'manual',
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/archived", ['archived' => true])
            ->assertOk();

        $this->assertNotNull($task->fresh()->archived_at);
    }

    public function test_archive_restore_clears_archived_at(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Stashed',
            'source' => 'manual',
            'archived_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/archived", ['archived' => false])
            ->assertOk();

        $this->assertNull($task->fresh()->archived_at);
    }

    public function test_archive_refuses_trello_source_task(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
            'trello_board_id' => 'b1',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'c1',
            'name' => 'Trello card',
            'source' => 'trello',
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/archived", ['archived' => true])
            ->assertStatus(422);

        $this->assertNull($task->fresh()->archived_at);
    }

    public function test_update_list_name_refuses_trello_source_task(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'P',
            'trello_board_id' => 'b1',
        ]);
        $task = Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'c1',
            'name' => 'Trello card',
            'source' => 'trello',
            'list_name' => 'Backlog',
        ]);

        $this->actingAs($this->user)
            ->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Doing'])
            ->assertStatus(422);

        $this->assertSame('Backlog', $task->fresh()->list_name);
    }

    public function test_create_manual_task_under_private_project(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Private',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/tasks", [
                'name' => 'Renew SSL',
                'list_name' => 'To-Do',
                'due_date' => '2026-06-01',
                'priority' => 'high',
            ])
            ->assertRedirect();

        $task = Task::where('project_id', $project->id)->firstOrFail();
        $this->assertSame('Renew SSL', $task->name);
        $this->assertSame('manual', $task->source);
        $this->assertSame('high', $task->priority);
        $this->assertTrue($task->is_reviewed);
        $this->assertFalse($task->is_completed);
    }

    public function test_create_task_marks_completed_when_status_done(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Private',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/tasks", [
                'name' => 'Already done',
                'list_name' => 'Done',
            ])
            ->assertRedirect();

        $task = Task::where('project_id', $project->id)->firstOrFail();
        $this->assertTrue($task->is_completed);
    }

    public function test_manual_task_allowed_on_trello_backed_project(): void
    {
        // Manual tasks may live on a Trello-backed project — they're stored
        // locally only (no trello_card_id) and the one-way Trello sync ignores
        // them. Useful for tracking work that shouldn't be visible to clients
        // on the shared Trello board.
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Trello-backed',
            'trello_board_id' => 'board_abc',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/tasks", [
                'name' => 'Internal-only note',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'name' => 'Internal-only note',
            'source' => 'manual',
            'trello_card_id' => null,
        ]);
    }

    public function test_update_manual_task(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Private',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Original',
            'source' => 'manual',
            'list_name' => 'To-Do',
        ]);

        $this->actingAs($this->user)
            ->put("/tasks/{$task->id}", [
                'name' => 'Updated',
                'list_name' => 'Doing',
                'priority' => 'medium',
            ])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame('Updated', $task->name);
        $this->assertSame('Doing', $task->list_name);
        $this->assertSame('medium', $task->priority);
    }

    public function test_update_refused_on_non_manual_task(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Trello',
            'trello_board_id' => 'board_xyz',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_001',
            'name' => 'From Trello',
            'source' => 'trello',
        ]);

        $this->actingAs($this->user)
            ->put("/tasks/{$task->id}", [
                'name' => 'Hijack attempt',
            ])
            ->assertStatus(422);

        $task->refresh();
        $this->assertSame('From Trello', $task->name);
    }

    public function test_destroy_manual_task(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Private',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Goodbye',
            'source' => 'manual',
        ]);

        $this->actingAs($this->user)
            ->delete("/tasks/{$task->id}")
            ->assertRedirect();

        $this->assertNull(Task::find($task->id));
    }
}

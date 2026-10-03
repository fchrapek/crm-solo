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

/**
 * What Trello owns is decided by the card, not the source label: a synced
 * card is read-only here whatever its source says, and a task with no card
 * is the CRM's to edit even if its source says trello.
 */
final class TaskCardOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
    }

    public function test_a_card_labelled_manual_is_still_read_only(): void
    {
        $card = Task::create(['project_id' => $this->project->id, 'name' => 'Card', 'source' => 'manual', 'trello_card_id' => 'c1', 'list_name' => 'Doing']);

        $this->actingAs($this->user)->put("/tasks/{$card->id}", ['name' => 'Renamed'])->assertStatus(422);
        $this->actingAs($this->user)->patchJson("/tasks/{$card->id}/archived", ['archived' => true])->assertStatus(422);
        $this->actingAs($this->user)->patchJson("/tasks/{$card->id}/list-name", ['list_name' => 'Testing'])->assertStatus(422);
        $this->actingAs($this->user)->delete("/tasks/{$card->id}")->assertStatus(422);

        $this->assertSame('Card', $card->fresh()->name);
    }

    public function test_a_cardless_task_labelled_trello_is_editable_and_reopens_by_lane(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Local', 'source' => 'trello', 'list_name' => 'Doing']);

        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Done'])->assertOk();
        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/list-name", ['list_name' => 'Doing'])
            ->assertOk()
            ->assertJson(['list_name' => 'Doing', 'is_completed' => false, 'finished_at' => null]);
        $this->actingAs($this->user)->put("/tasks/{$task->id}", ['name' => 'Renamed'])->assertRedirect();
        $this->actingAs($this->user)->patchJson("/tasks/{$task->id}/archived", ['archived' => true])->assertOk();

        $this->assertSame('Renamed', $task->fresh()->name);
    }

    public function test_pages_tell_the_frontend_which_tasks_are_cards(): void
    {
        $card = Task::create(['project_id' => $this->project->id, 'name' => 'Card', 'source' => 'manual', 'trello_card_id' => 'c1']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Local', 'source' => 'trello']);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/projects/{$this->project->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks', fn ($tasks) => collect($tasks)->pluck('has_trello_card', 'name')->all() === ['Card' => true, 'Local' => false])
            );
        $this->actingAs($this->user)->get("/tasks/{$card->id}")
            ->assertInertia(fn (Assert $page) => $page->where('task.has_trello_card', true));
    }

    public function test_a_finished_card_reads_done_in_its_parents_child_list(): void
    {
        $parent = Task::create(['project_id' => $this->project->id, 'name' => 'Parent', 'source' => 'manual']);
        Task::create([
            'project_id' => $this->project->id, 'name' => 'Child card', 'trello_card_id' => 'c9', 'parent_task_id' => $parent->id,
            'list_name' => 'Testing', 'is_completed' => false, 'finished_at' => now(),
        ]);

        $this->actingAs($this->user)->get("/tasks/{$parent->id}")
            ->assertInertia(fn (Assert $page) => $page->where('task.child_tasks.0.is_done', true)->where('task.child_tasks.0.is_completed', false));
    }
}

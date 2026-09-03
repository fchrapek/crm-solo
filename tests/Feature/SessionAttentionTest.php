<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\SessionAttention;
use App\Models\Account;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class SessionAttentionTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'P',
        ]);
    }

    public function test_unknown_token_returns_404_without_leaking(): void
    {
        Event::fake([SessionAttention::class]);

        $this->postJson('/api/session-events/totally-bogus-token', ['event' => 'notification'])
            ->assertStatus(404)
            ->assertJson(['ok' => false]);

        Event::assertNotDispatched(SessionAttention::class);
    }

    public function test_valid_token_sets_attention_and_broadcasts(): void
    {
        Event::fake([SessionAttention::class]);

        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Polish landing hero',
            'cli' => Task::CLI_CLAUDE,
            'session_pid' => 123,
            'session_port' => 7681,
            'session_token' => 'sek-token-abc',
        ]);

        $this->postJson('/api/session-events/sek-token-abc', [
            'event' => 'notification',
            'message' => 'Claude is waiting for your input',
        ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $task->refresh();
        $this->assertNotNull($task->session_attention_at);

        Event::assertDispatched(SessionAttention::class, function (SessionAttention $event) use ($task) {
            return $event->taskId === $task->id
                && $event->taskName === $task->name
                && $event->event === 'notification'
                && $event->message === 'Claude is waiting for your input';
        });
    }

    public function test_endpoint_validates_event_field(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'x',
            'cli' => Task::CLI_CLAUDE,
            'session_token' => 'tok-validate',
        ]);

        $this->postJson('/api/session-events/tok-validate', [])
            ->assertStatus(422);
    }

    public function test_clear_attention_endpoint_nulls_the_flag(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'x',
            'cli' => Task::CLI_CLAUDE,
            'session_attention_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/clear-session-attention")
            ->assertOk()
            ->assertJson(['cleared' => true]);

        $this->assertNull($task->fresh()->session_attention_at);
    }

    public function test_clear_attention_is_idempotent_when_already_null(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'x',
            'cli' => Task::CLI_CLAUDE,
            'session_attention_at' => null,
        ]);

        $this->actingAs($this->user)
            ->postJson("/tasks/{$task->id}/clear-session-attention")
            ->assertOk();

        $this->assertNull($task->fresh()->session_attention_at);
    }
}

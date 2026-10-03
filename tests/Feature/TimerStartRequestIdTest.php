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
use Tests\TestCase;

/**
 * One click on Start opens one timer, however often its request arrives.
 * A new click is a new intent and opens another, on the same task or not.
 */
final class TimerStartRequestIdTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
        $this->task = Task::create(['project_id' => $project->id, 'name' => 'Header', 'source' => 'manual']);
    }

    public function test_the_same_request_twice_opens_one_timer_and_returns_it(): void
    {
        $id = '2b9a0c3e-6f4d-4a51-9b7e-1c2d3e4f5a6b';

        $first = $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => $id])->assertCreated();
        $second = $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => $id])->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, TimeEntry::count());
    }

    public function test_a_replay_after_the_stop_still_returns_the_original_timer(): void
    {
        $id = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $first = $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => $id])->assertCreated()->json('id');
        $this->actingAs($this->user)->postJson("/time-entries/{$first}/stop")->assertOk();

        $replay = $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => $id])->assertOk();

        $this->assertSame($first, $replay->json('id'));
        $this->assertNotNull($replay->json('end_time'));
        $this->assertSame(0, TimeEntry::whereNull('end_time')->count());
    }

    public function test_the_same_request_id_on_another_task_is_a_conflict(): void
    {
        $id = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $other = Task::create(['project_id' => $this->task->project_id, 'name' => 'Footer', 'source' => 'manual']);
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => $id])->assertCreated();

        $this->actingAs($this->user)->postJson("/tasks/{$other->id}/time-entries/start", ['request_id' => $id])->assertStatus(409);

        $this->assertSame(1, TimeEntry::count());
        $this->assertSame(0, TimeEntry::where('task_id', $other->id)->count());
    }

    public function test_a_second_click_on_the_same_task_opens_a_second_timer(): void
    {
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])->assertCreated();
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'])->assertCreated();

        $this->assertSame(2, TimeEntry::whereNull('end_time')->where('task_id', $this->task->id)->count());
    }

    public function test_a_start_without_a_request_id_still_opens_a_timer_each_time(): void
    {
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start")->assertCreated();
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start")->assertCreated();

        $this->assertSame(2, TimeEntry::count());
    }

    public function test_another_accounts_request_id_never_returns_its_timer(): void
    {
        $id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => $id])->assertCreated();

        $other = Account::create(['name' => 'Other']);
        $otherUser = User::factory()->create(['account_id' => $other->id, 'owner' => true]);
        $otherProject = Project::create(['account_id' => $other->id, 'name' => 'Theirs']);
        $otherTask = Task::create(['project_id' => $otherProject->id, 'name' => 'Theirs', 'source' => 'manual']);

        $response = $this->actingAs($otherUser)->postJson("/tasks/{$otherTask->id}/time-entries/start", ['request_id' => $id])->assertCreated();

        $this->assertSame($otherTask->id, TimeEntry::find($response->json('id'))->task_id);
        $this->assertSame(2, TimeEntry::count());
    }

    public function test_a_malformed_request_id_is_rejected(): void
    {
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start", ['request_id' => 'click-1'])->assertUnprocessable();

        $this->assertSame(0, TimeEntry::count());
    }
}

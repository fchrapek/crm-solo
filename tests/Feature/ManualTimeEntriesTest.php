<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSession;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManualTimeEntriesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private Project $project;

    private Task $task;

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
        $this->client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'ACME',
            'type' => 'business',
        ]);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Site',
        ]);
        $this->task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Hero work',
        ]);
    }

    public function test_manual_entry_with_end_time_creates_closed_row(): void
    {
        $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/time-entries", [
                'description' => 'Refactored hero',
                'start_time' => '2026-05-21T10:00:00Z',
                'end_time' => '2026-05-21T11:30:00Z',
                'billable' => true,
            ])
            ->assertCreated();

        $entry = TimeEntry::first();
        $this->assertSame(TimeEntry::SOURCE_MANUAL, $entry->source);
        $this->assertSame($this->task->id, $entry->task_id);
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame($this->client->id, $entry->client_id);
        $this->assertSame(90, $entry->duration_minutes);
        $this->assertNotNull($entry->end_time);
    }

    public function test_manual_entry_with_duration_only_derives_end_time(): void
    {
        $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/time-entries", [
                'start_time' => '2026-05-21T10:00:00Z',
                'duration_minutes' => 45,
            ])
            ->assertCreated();

        $entry = TimeEntry::first();
        $this->assertSame(45, $entry->duration_minutes);
        $this->assertNotNull($entry->end_time);
    }

    public function test_running_endpoint_surfaces_open_entries(): void
    {
        // Auto-tracked open entry — what conflict detection should warn about.
        $running = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_TERMINAL_SESSION,
            'description' => 'Live session',
            'start_time' => now()->subMinutes(20),
            'end_time' => null,
            'duration_minutes' => 0,
            'billable' => true,
        ]);
        // Closed entry — should NOT appear in the running list.
        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'description' => 'Earlier work',
            'start_time' => now()->subHour(),
            'end_time' => now()->subMinutes(30),
            'duration_minutes' => 30,
            'billable' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/time-entries/running')
            ->assertOk();

        $entries = $response->json('entries');
        $this->assertCount(1, $entries);
        $this->assertSame($running->id, $entries[0]['id']);
        $this->assertSame(TimeEntry::SOURCE_TERMINAL_SESSION, $entries[0]['source']);
        $this->assertSame($this->task->id, $entries[0]['task']['id']);
    }

    public function test_update_recomputes_duration_when_end_time_changes(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-05-21 10:00:00',
            'end_time' => '2026-05-21 10:30:00',
            'duration_minutes' => 30,
            'billable' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", [
                'end_time' => '2026-05-21T11:00:00Z',
            ])
            ->assertOk();

        $this->assertSame(60, $entry->fresh()->duration_minutes);
    }

    public function test_update_saves_title_and_description_separately(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-06-21 09:00:00',
            'end_time' => '2026-06-21 16:00:00',
            'duration_minutes' => 420,
            'billable' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", [
                'title' => 'Audyt Google Ads',
                'description' => 'Analiza search terms EN, budżety, CPC, rekomendacje.',
            ])
            ->assertOk()
            ->assertJson(['title' => 'Audyt Google Ads']);

        $fresh = $entry->fresh();
        $this->assertSame('Audyt Google Ads', $fresh->title);
        $this->assertSame('Analiza search terms EN, budżety, CPC, rekomendacje.', $fresh->description);
    }

    public function test_update_connects_and_clears_task(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => null,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-06-02 09:00:00',
            'end_time' => '2026-06-02 12:00:00',
            'duration_minutes' => 180,
            'billable' => true,
        ]);

        // Connect a task — task_id set, project/client backfilled from it.
        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", ['task_id' => $this->task->id])
            ->assertOk();
        $fresh = $entry->fresh();
        $this->assertSame($this->task->id, $fresh->task_id);
        $this->assertSame($this->project->id, $fresh->project_id);

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", ['task_id' => null])
            ->assertOk();
        $this->assertNull($entry->fresh()->task_id);
    }

    public function test_update_ignores_task_from_other_account(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $otherProject = Project::create([
            'account_id' => $otherAccount->id,
            'name' => 'Other site',
        ]);
        $otherTask = Task::create(['project_id' => $otherProject->id, 'name' => 'Theirs']);

        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => null,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-06-02 09:00:00',
            'end_time' => '2026-06-02 12:00:00',
            'duration_minutes' => 180,
            'billable' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", ['task_id' => $otherTask->id])
            ->assertOk();

        // Cross-account task is silently ignored — never connected.
        $this->assertNull($entry->fresh()->task_id);
    }

    public function test_destroy_removes_entry(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => now(),
            'duration_minutes' => 10,
            'billable' => false,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/time-entries/{$entry->id}")
            ->assertOk();

        $this->assertNull($entry->fresh());
    }

    public function test_start_opens_running_manual_entry(): void
    {
        $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/time-entries/start")
            ->assertCreated();

        $entry = TimeEntry::first();
        $this->assertSame(TimeEntry::SOURCE_MANUAL, $entry->source);
        $this->assertSame($this->task->id, $entry->task_id);
        $this->assertNull($entry->end_time);
        $this->assertNotNull($entry->start_time);
    }

    public function test_stop_closes_entry_and_sets_duration(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => now()->subMinutes(45),
            'end_time' => null,
            'duration_minutes' => 0,
            'billable' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson("/time-entries/{$entry->id}/stop")
            ->assertOk();

        $entry->refresh();
        $this->assertNotNull($entry->end_time);
        $this->assertGreaterThanOrEqual(44, $entry->duration_minutes);
        $this->assertLessThanOrEqual(46, $entry->duration_minutes);
    }

    public function test_stop_refuses_already_closed_entry(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-05-21 10:00:00',
            'end_time' => '2026-05-21 10:30:00',
            'duration_minutes' => 30,
            'billable' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson("/time-entries/{$entry->id}/stop")
            ->assertStatus(422);
    }

    public function test_update_accepts_overnight_duration(): void
    {
        // The previous validator capped duration_minutes at 1440 (24h), which
        // silently rejected edits on overnight sessions (claude left running
        // over a weekend, then trimmed to reality). 19h+ now passes — only
        // start_time/end_time still need to be coherent.
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_TERMINAL_SESSION,
            'start_time' => '2026-05-21 22:00:00',
            'end_time' => '2026-05-22 23:30:00',
            'duration_minutes' => 1530,
            'billable' => true,
        ]);

        // 26h span > 1440 minutes. The old cap rejected this with 422; new
        // behavior is no cap, so the diff goes through and duration reflects
        // the actual elapsed time.
        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", [
                'start_time' => '2026-05-21T22:00:00Z',
                'end_time' => '2026-05-23T00:00:00Z',
            ])
            ->assertOk();

        $this->assertGreaterThan(1440, $entry->fresh()->duration_minutes);
    }

    public function test_update_syncs_linked_task_session_times(): void
    {
        // Session history rows surface started_at/ended_at from TaskSession,
        // not the TimeEntry — but the user edits the TimeEntry. Update must
        // patch both so the row above the dialog reflects the correction.
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_TERMINAL_SESSION,
            'start_time' => '2026-05-21 10:00:00',
            'end_time' => '2026-05-21 12:00:00',
            'duration_minutes' => 120,
            'billable' => true,
        ]);
        $session = TaskSession::create([
            'task_id' => $this->task->id,
            'account_id' => $this->account->id,
            'cli' => 'claude',
            'base_branch' => 'main',
            'branch_name' => 'session/task-'.$this->task->id,
            'worktree_path' => '/tmp/wt',
            'time_entry_id' => $entry->id,
            'started_at' => '2026-05-21 10:00:00',
            'ended_at' => '2026-05-21 12:00:00',
            'ended_reason' => TaskSession::ENDED_STOPPED,
        ]);

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", [
                'start_time' => '2026-05-21T10:30:00Z',
                'end_time' => '2026-05-21T11:45:00Z',
            ])
            ->assertOk();

        $session->refresh();
        $this->assertSame('2026-05-21 10:30:00', $session->started_at->utc()->toDateTimeString());
        $this->assertSame('2026-05-21 11:45:00', $session->ended_at->utc()->toDateTimeString());
    }

    public function test_cross_account_update_is_forbidden(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $other = User::factory()->create([
            'account_id' => $otherAccount->id,
            'first_name' => 'O',
            'last_name' => 'U',
            'email' => 'other@example.com',
            'owner' => true,
        ]);
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => now(),
            'duration_minutes' => 10,
            'billable' => true,
        ]);

        $this->actingAs($other)
            ->putJson("/time-entries/{$entry->id}", ['description' => 'pwned'])
            ->assertStatus(403);
    }
}

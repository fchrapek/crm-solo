<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use App\Services\Time\TimeEntryChanged;
use App\Services\Time\TimeEntryWriter;
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

    public function test_moving_the_start_alone_recounts_the_minutes_to_the_kept_end(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-06-02 09:00:00',
            'end_time' => '2026-06-02 12:00:00',
            'duration_minutes' => 180,
            'billable' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", ['start_time' => '2026-06-02T10:00:00Z'])
            ->assertOk()
            ->assertJsonPath('duration_minutes', 120);

        $this->assertSame(['2026-06-02 12:00:00', 120], [$entry->fresh()->end_time->toDateTimeString(), $entry->fresh()->duration_minutes]);
    }

    public function test_a_task_in_a_project_without_a_client_takes_the_client_off_the_entry(): void
    {
        $internal = Project::create(['account_id' => $this->account->id, 'name' => 'Internal']);
        $chore = Task::create(['project_id' => $internal->id, 'name' => 'Bookkeeping']);
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-06-02 09:00:00',
            'end_time' => '2026-06-02 10:00:00',
            'duration_minutes' => 60,
            'billable' => true,
        ]);

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['task_id' => $chore->id])->assertOk();

        $this->assertSame([$chore->id, $internal->id, null], [$entry->fresh()->task_id, $entry->fresh()->project_id, $entry->fresh()->client_id]);
    }

    public function test_the_dialogs_edit_writes_no_agent_audit_entry(): void
    {
        $entry = $this->juneEntry();

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['duration_minutes' => 90, 'description' => 'Corrected'])->assertOk();

        $this->assertSame([90, 'Corrected'], [$entry->fresh()->duration_minutes, $entry->fresh()->description]);
        $this->assertSame(0, AgentAuditEvent::query()->count());
    }

    public function test_the_dialog_cannot_change_time_in_a_closed_month_and_says_why(): void
    {
        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-06', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
        $entry = $this->juneEntry();

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['duration_minutes' => 90, 'description' => 'Corrected'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'June 2026 is closed. Reopen it to change its time.']);

        $this->assertSame([60, null], [$entry->fresh()->duration_minutes, $entry->fresh()->description]);
    }

    public function test_the_dialog_cannot_move_time_into_a_closed_month_or_onto_a_client_whose_month_is_closed(): void
    {
        $other = Client::create(['account_id' => $this->account->id, 'name' => 'Closed Co', 'type' => 'business']);
        $closedTask = Task::create(['project_id' => Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Theirs'])->id, 'name' => 'Their task']);
        ClientMonthClosure::create(['client_id' => $other->id, 'period' => '2026-06', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-05', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
        $entry = $this->juneEntry();

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['task_id' => $closedTask->id])->assertUnprocessable();
        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['start_time' => '2026-05-30T09:00:00Z', 'end_time' => '2026-05-30T10:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'May 2026 is closed. Reopen it to change its time.');

        $this->assertSame([$this->task->id, '2026-06-02 09:00:00'], [$entry->fresh()->task_id, $entry->fresh()->start_time->toDateTimeString()]);
    }

    public function test_the_dialog_cannot_change_time_a_finalized_report_covers(): void
    {
        ClientReport::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_FINALIZED, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
            'contracted_hours' => 10, 'opening_balance_hours' => 0, 'actual_hours' => 1, 'composer_key' => 'manual',
        ]);
        $entry = $this->juneEntry();

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['duration_minutes' => 90])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The report for June 2026 is finalized or sent. Reopen it to change its time.');

        $this->assertSame(60, $entry->fresh()->duration_minutes);
    }

    public function test_a_closed_month_still_takes_a_new_title_and_description(): void
    {
        $this->closeJune();
        $entry = $this->juneEntry();

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['title' => 'Hero', 'description' => 'Hero banner copy'])->assertOk();

        $this->assertSame(['Hero', 'Hero banner copy', 60], [$entry->fresh()->title, $entry->fresh()->description, $entry->fresh()->duration_minutes]);
    }

    public function test_a_closed_month_refuses_each_number_of_an_entry(): void
    {
        $this->closeJune();
        $entry = $this->juneEntry();
        $sibling = Task::create(['project_id' => $this->project->id, 'name' => 'Footer work']);

        foreach ([
            'minutes' => ['duration_minutes' => 45],
            'start' => ['start_time' => '2026-06-02T08:30:00Z'],
            'end' => ['start_time' => '2026-06-02T09:00:00Z', 'end_time' => '2026-06-02T11:00:00Z'],
            'task' => ['task_id' => $sibling->id],
            'no task' => ['task_id' => null],
            'billable' => ['billable' => false],
        ] as $field => $payload) {
            $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", $payload)
                ->assertUnprocessable()
                ->assertExactJson(['message' => 'June 2026 is closed. Reopen it to change its time.']);
        }

        $fresh = $entry->fresh();
        $this->assertSame(
            [$this->task->id, '2026-06-02 09:00:00', '2026-06-02 10:00:00', 60, true],
            [$fresh->task_id, $fresh->start_time->toDateTimeString(), $fresh->end_time->toDateTimeString(), $fresh->duration_minutes, $fresh->billable],
        );
    }

    public function test_the_dialogs_whole_form_with_only_a_new_description_saves_in_a_closed_month(): void
    {
        $this->closeJune();
        $entry = $this->juneEntry();
        $entry->update(['start_time' => '2026-06-02 09:00:27', 'end_time' => '2026-06-02 10:00:41', 'duration_minutes' => 61]);

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", [
            'title' => null,
            'description' => 'Hero banner copy',
            'task_id' => $this->task->id,
            'start_time' => '2026-06-02T09:00:00.000Z',
            'end_time' => '2026-06-02T10:00:00.000Z',
            'billable' => true,
        ])->assertOk();

        $fresh = $entry->fresh();
        $this->assertSame(
            ['Hero banner copy', '2026-06-02 09:00:27', '2026-06-02 10:00:41', 61],
            [$fresh->description, $fresh->start_time->toDateTimeString(), $fresh->end_time->toDateTimeString(), $fresh->duration_minutes],
        );
    }

    public function test_a_closed_month_refuses_deleting_an_entry(): void
    {
        $this->closeJune();
        $entry = $this->juneEntry();

        $this->actingAs($this->user)->deleteJson("/time-entries/{$entry->id}")
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'June 2026 is closed. Reopen it to change its time.']);

        $this->assertNotNull($entry->fresh());
    }

    public function test_a_closed_month_refuses_a_new_entry_dated_inside_it_and_takes_one_after_it(): void
    {
        $this->closeJune();

        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries", ['start_time' => '2026-06-30T20:00:00Z', 'end_time' => '2026-06-30T21:00:00Z'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'June 2026 is closed. Reopen it to change its time.']);
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries", ['start_time' => '2026-07-01T08:00:00Z', 'duration_minutes' => 30])
            ->assertCreated();

        $this->assertSame(['2026-07-01 08:00:00'], TimeEntry::query()->get()->map(fn (TimeEntry $e): string => $e->start_time->toDateTimeString())->all());
    }

    public function test_a_reopened_month_takes_new_numbers_new_entries_and_deletes_again(): void
    {
        $closure = $this->closeJune();
        $entry = $this->juneEntry();
        $doomed = $this->juneEntry();
        $this->assertSame(SettlementOutcome::Applied, app(MonthSettlement::class)->reopen($this->client, '2026-06', $closure->id)->outcome);

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['duration_minutes' => 90])->assertOk();
        $this->actingAs($this->user)->deleteJson("/time-entries/{$doomed->id}")->assertOk();
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries", ['start_time' => '2026-06-03T08:00:00Z', 'duration_minutes' => 30])->assertCreated();

        $this->assertSame([90, 30], TimeEntry::query()->orderBy('start_time')->pluck('duration_minutes')->all());
    }

    public function test_a_new_start_with_the_same_minutes_moves_the_whole_entry(): void
    {
        $entry = $this->juneEntry();

        $this->actingAs($this->user)->putJson("/time-entries/{$entry->id}", ['start_time' => '2026-06-02T10:00:00Z', 'duration_minutes' => 60])->assertOk();

        $fresh = $entry->fresh();
        $this->assertSame(['2026-06-02 10:00:00', '2026-06-02 11:00:00', 60], [$fresh->start_time->toDateTimeString(), $fresh->end_time->toDateTimeString(), $fresh->duration_minutes]);
    }

    public function test_a_write_from_a_copy_read_before_the_entry_moved_is_refused(): void
    {
        $entry = $this->juneEntry();
        $stale = TimeEntry::query()->findOrFail($entry->id);
        TimeEntry::query()->whereKey($entry->id)->update(['start_time' => '2026-06-02 09:30:00']);

        try {
            app(TimeEntryWriter::class)->update($stale, ['description' => 'Late note']);
            $this->fail('A stale copy was written.');
        } catch (TimeEntryChanged) {
        }

        $this->assertNull($entry->fresh()->description);
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

    private function closeJune(): ClientMonthClosure
    {
        return ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-06', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
    }

    private function juneEntry(): TimeEntry
    {
        return TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-06-02 09:00:00',
            'end_time' => '2026-06-02 10:00:00',
            'duration_minutes' => 60,
            'billable' => true,
        ]);
    }
}

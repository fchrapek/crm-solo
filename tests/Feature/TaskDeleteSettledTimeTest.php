<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TaskDeleteSettledTimeTest extends TestCase
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
        $this->client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $this->task = Task::create(['project_id' => $this->project->id, 'name' => 'Hero work', 'source' => 'manual']);
    }

    public function test_a_task_with_time_in_a_closed_month_is_not_deleted_and_archiving_is_offered(): void
    {
        $this->closeJune();
        $entry = $this->juneEntry($this->task);

        $this->actingAs($this->user)->deleteJson("/tasks/{$this->task->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archive_instead' => 'This task has time in a closed month (June 2026). Archive it instead of deleting.']);

        $this->assertNotNull($this->task->fresh());
        $this->assertSame($this->task->id, $entry->fresh()->task_id);
    }

    public function test_a_task_with_time_a_finalized_report_covers_is_not_deleted(): void
    {
        ClientReport::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_FINALIZED, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
            'contracted_hours' => 10, 'opening_balance_hours' => 0, 'actual_hours' => 1, 'composer_key' => 'manual',
        ]);
        $entry = $this->juneEntry($this->task);

        $this->actingAs($this->user)->deleteJson("/tasks/{$this->task->id}")
            ->assertJsonValidationErrors(['archive_instead' => 'This task has time in a finalized or sent report (June 2026). Archive it instead of deleting.']);

        $this->assertNotNull($this->task->fresh());
        $this->assertSame($this->task->id, $entry->fresh()->task_id);
    }

    public function test_a_refused_delete_leaves_the_subtasks_attached(): void
    {
        $this->closeJune();
        $this->juneEntry($this->task);
        $child = Task::create(['project_id' => $this->project->id, 'name' => 'Hero copy', 'source' => 'manual', 'parent_task_id' => $this->task->id]);

        $this->actingAs($this->user)->deleteJson("/tasks/{$this->task->id}")->assertJsonValidationErrors('archive_instead');

        $this->assertSame($this->task->id, $child->fresh()->parent_task_id);
    }

    public function test_a_task_whose_time_is_not_settled_deletes_and_its_time_stays(): void
    {
        $this->closeJune();
        $entry = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'client_id' => $this->client->id, 'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-07-02 09:00:00', 'end_time' => '2026-07-02 10:00:00', 'duration_minutes' => 60,
        ]);

        $this->actingAs($this->user)->deleteJson("/tasks/{$this->task->id}")
            ->assertOk()
            ->assertExactJson(['deleted' => $this->task->id]);

        $this->assertNull(Task::find($this->task->id));
        $this->assertNull($entry->fresh()->task_id);
        $this->assertSame(60, $entry->fresh()->duration_minutes);
    }

    public function test_a_task_with_time_in_a_closed_month_can_still_be_archived(): void
    {
        $this->closeJune();
        $this->juneEntry($this->task);

        $this->actingAs($this->user)->patchJson("/tasks/{$this->task->id}/archived", ['archived' => true])->assertOk();

        $this->assertNotNull($this->task->fresh()->archived_at);
    }

    public function test_tasks_delete_keeps_the_tasks_with_settled_time_and_deletes_the_rest(): void
    {
        $this->closeJune();
        $this->juneEntry($this->task);
        $free = Task::create(['project_id' => $this->project->id, 'name' => 'Footer', 'source' => 'manual']);

        $this->artisan('tasks:delete', ['--project' => (string) $this->project->id, '--force' => true])
            ->expectsOutputToContain('This task has time in a closed month (June 2026). Archive it instead of deleting.')
            ->expectsOutputToContain('Deleted 1 tasks.')
            ->assertSuccessful();

        $this->assertNotNull($this->task->fresh());
        $this->assertNull(Task::find($free->id));
    }

    public function test_a_project_with_settled_task_time_is_not_deleted_from_the_web_or_the_command(): void
    {
        $this->closeJune();
        $this->juneEntry($this->task);
        $free = Task::create(['project_id' => $this->project->id, 'name' => 'Footer', 'source' => 'manual']);

        $this->actingAs($this->user)->delete("/projects/{$this->project->id}")
            ->assertSessionHas('error', 'This project has time in a closed month (June 2026), so it cannot be deleted.');
        $this->artisan('projects:delete', ['project' => (string) $this->project->id, '--force' => true])
            ->expectsOutputToContain('This project has time in a closed month (June 2026), so it cannot be deleted. Nothing was deleted.')
            ->assertFailed();

        $this->assertNotNull($this->project->fresh());
        $this->assertNotNull($free->fresh());
        $this->assertNotNull($this->task->fresh());
    }

    public function test_a_refused_project_delete_keeps_the_attachments_of_tasks_deleted_before_the_refusal(): void
    {
        Storage::fake('local');
        $first = Task::create(['project_id' => $this->project->id, 'name' => 'Footer', 'source' => 'manual']);
        $settled = Task::create(['project_id' => $this->project->id, 'name' => 'Header', 'source' => 'manual']);
        $this->closeJune();
        $this->juneEntry($settled);
        Storage::disk('local')->put("task-attachments/{$first->id}/brief.pdf", 'pdf');

        $this->actingAs($this->user)->delete("/projects/{$this->project->id}")->assertSessionHas('error');

        $this->assertNotNull($first->fresh());
        Storage::disk('local')->assertExists("task-attachments/{$first->id}/brief.pdf");
    }

    public function test_a_deleted_task_takes_its_attachment_files_with_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put("task-attachments/{$this->task->id}/brief.pdf", 'pdf');

        $this->actingAs($this->user)->deleteJson("/tasks/{$this->task->id}")->assertOk();

        Storage::disk('local')->assertMissing("task-attachments/{$this->task->id}");
    }

    private function closeJune(): void
    {
        ClientMonthClosure::create(['client_id' => $this->client->id, 'period' => '2026-06', 'closed_at' => now(), 'snapshot' => ['closing' => 0.0]]);
    }

    private function juneEntry(Task $task): TimeEntry
    {
        return TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-06-02 09:00:00',
            'end_time' => '2026-06-02 10:00:00',
            'duration_minutes' => 60,
            'billable' => true,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\TimeLogger;
use App\Services\Agent\TimerService;
use App\Services\Day\DayPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Time entries are recorded locally only: an integrations row left behind by
 * a removed time-tracking provider must not turn any write into an API call.
 */
final class TimeEntriesStayLocalTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Task $task;

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
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'General']);
        $this->task = Task::create(['project_id' => $project->id, 'name' => 'Hero work']);

        Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'clockify',
            'is_enabled' => true,
            'api_key' => 'leftover-key',
        ]);

        Http::fake();
    }

    public function test_logging_editing_and_stopping_from_the_ui_sends_nothing(): void
    {
        $created = $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/time-entries", [
                'start_time' => '2026-09-01T10:00:00Z',
                'end_time' => '2026-09-01T11:00:00Z',
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$created}", ['description' => 'trimmed'])
            ->assertOk();

        $running = $this->actingAs($this->user)
            ->postJson("/tasks/{$this->task->id}/time-entries/start")
            ->assertCreated()
            ->json('id');

        $this->actingAs($this->user)
            ->postJson("/time-entries/{$running}/stop")
            ->assertOk();

        $this->assertNotNull(TimeEntry::find($running)->end_time);
        Http::assertNothingSent();
    }

    public function test_editing_an_entry_imported_from_clockify_stays_local(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->task->project_id,
            'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_CLOCKIFY,
            'description' => 'imported',
            'start_time' => '2026-06-01 10:00:00',
            'end_time' => '2026-06-01 11:00:00',
            'duration_minutes' => 60,
        ]);
        $entry->forceFill(['clockify_entry_id' => 'historical-id'])->save();

        $this->actingAs($this->user)
            ->putJson("/time-entries/{$entry->id}", ['description' => 'corrected', 'end_time' => '2026-06-01T11:30:00Z'])
            ->assertOk();

        $entry->refresh();
        $this->assertSame('corrected', $entry->description);
        $this->assertSame(90, $entry->duration_minutes);
        $this->assertSame('historical-id', $entry->clockify_entry_id);
        Http::assertSentCount(0);
    }

    public function test_agent_verbs_and_completing_a_task_send_nothing(): void
    {
        app(TimeLogger::class)->log(minutes: 30, task: (string) $this->task->id, accountId: $this->account->id);

        $timers = app(TimerService::class);
        $timers->stop($timers->start($this->task));

        $timers->start($this->task);
        app(DayPlanner::class)->completeTask($this->task);

        $this->assertSame(0, TimeEntry::whereNull('end_time')->count());
        $this->assertTrue($this->task->fresh()->is_completed);
        Http::assertNothingSent();
    }
}

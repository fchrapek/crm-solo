<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The crm:* agent verb contract — the CLI surface terminal agents use to
 * manage the CRM. Verbs must behave exactly like their UI counterparts
 * (canonical completion path, append-only histories, model-level guards).
 */
final class CrmVerbsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Verb Test Co']);
    }

    public function test_brief_outputs_client_context_as_json(): void
    {
        $this->artisan('crm:brief', ['client' => (string) $this->client->id, '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"name":"Verb Test Co"');
    }

    public function test_brief_resolves_by_name_fragment(): void
    {
        $this->artisan('crm:brief', ['client' => 'Verb Test'])
            ->assertSuccessful()
            ->expectsOutputToContain('Verb Test Co');
    }

    public function test_brief_lists_candidates_on_ambiguity(): void
    {
        Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Verb Test Two']);

        $this->artisan('crm:brief', ['client' => 'Verb Test'])
            ->assertFailed()
            ->expectsOutputToContain('Ambiguous');
    }

    public function test_task_done_completes_and_spawns_recurrence(): void
    {
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'P']);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Weekly check',
            'source' => 'manual',
            'is_completed' => false,
            'recurrence_period_days' => 7,
            'cli' => 'claude',
            'agent_lane' => Task::AGENT_LANE_IN_PROGRESS,
        ]);

        $this->artisan('crm:task-done', ['task' => (string) $task->id])->assertSuccessful();

        $task->refresh();
        $this->assertTrue((bool) $task->is_completed);
        $this->assertSame('Done', $task->list_name);
        $this->assertSame(Task::AGENT_LANE_DONE, $task->agent_lane);

        $successor = Task::query()->where('parent_task_id', $task->id)->first();
        $this->assertNotNull($successor);
        $this->assertSame('claude', $successor->cli);
        $this->assertFalse((bool) $successor->is_completed);
    }

    public function test_task_done_is_idempotent_on_completed_tasks(): void
    {
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'P']);
        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Once',
            'source' => 'manual',
            'is_completed' => true,
            'list_name' => 'Done',
            'recurrence_period_days' => 7,
        ]);

        $this->artisan('crm:task-done', ['task' => (string) $task->id])->assertSuccessful();

        // Already-done tasks must not spawn another successor.
        $this->assertSame(0, Task::query()->where('parent_task_id', $task->id)->count());
    }

    public function test_note_appends_a_journal_event_without_changing_stage(): void
    {
        $stageBefore = $this->client->lifecycle_stage;
        $eventsBefore = $this->client->lifecycleEvents()->count();

        $this->artisan('crm:note', ['client' => (string) $this->client->id, 'note' => 'Called about renewal'])
            ->assertSuccessful();

        $this->client->refresh();
        $this->assertSame($stageBefore, $this->client->lifecycle_stage);
        $this->assertSame($eventsBefore + 1, $this->client->lifecycleEvents()->count());
        $this->assertSame('Called about renewal', $this->client->lifecycleEvents()->orderByDesc('id')->first()->note);
    }

    public function test_lead_capture_goes_through_model_validation(): void
    {
        $this->artisan('crm:lead-capture', [
            '--name' => 'CLI Lead',
            '--source' => 'outbound',
            '--company' => 'Acme',
        ])->assertSuccessful();

        $lead = Lead::query()->firstWhere('name', 'CLI Lead');
        $this->assertNotNull($lead);
        // Entry stage resolved by config, capture event written by the model.
        $this->assertSame(1, $lead->stageEvents()->count());
        $this->assertNull($lead->stageEvents()->first()->from_stage);
    }

    public function test_lead_capture_rejects_unknown_source(): void
    {
        $this->artisan('crm:lead-capture', [
            '--name' => 'Bad Lead',
            '--source' => 'carrier-pigeon',
        ])->assertFailed();

        $this->assertNull(Lead::query()->firstWhere('name', 'Bad Lead'));
    }

    public function test_lead_stage_records_the_hop(): void
    {
        $this->artisan('crm:lead-capture', ['--name' => 'Mover', '--source' => 'outbound'])->assertSuccessful();
        $lead = Lead::query()->firstWhere('name', 'Mover');

        $this->artisan('crm:lead-stage', ['lead' => (string) $lead->id, 'stage' => 'conversation'])
            ->assertSuccessful();

        $lead->refresh();
        $this->assertSame('conversation', $lead->stage);
        $this->assertSame(2, $lead->stageEvents()->count());
    }

    public function test_lead_stage_rejects_retired_vocabulary(): void
    {
        $this->artisan('crm:lead-capture', ['--name' => 'Stuck', '--source' => 'outbound'])->assertSuccessful();
        $lead = Lead::query()->firstWhere('name', 'Stuck');

        $this->artisan('crm:lead-stage', ['lead' => (string) $lead->id, 'stage' => 'mql'])->assertFailed();
    }

    public function test_today_reports_attention_and_running_timers_as_json(): void
    {
        $this->artisan('crm:today', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"attention_tasks"');
    }

    public function test_today_lists_every_open_month_close_oldest_first(): void
    {
        $this->travelTo('2026-10-03 09:00:00');
        $other = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Older Close Co']);
        foreach ([[$this->client, '2026-09', 'open'], [$other, '2026-08', 'open'], [$this->client, '2026-07', 'completed']] as [$client, $period, $status]) {
            \App\Models\MonthCloseRun::create([
                'account_id' => $this->account->id,
                'client_id' => $client->id,
                'period' => $period,
                'close_type' => 'maintenance',
                'status' => $status,
            ]);
        }

        $this->artisan('crm:today')
            ->assertSuccessful()
            ->expectsOutputToContain('2026-08 Older Close Co (maintenance)')
            ->expectsOutputToContain('2026-09 Verb Test Co (maintenance)');

        $this->artisan('crm:today', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"month_close_open":[{"client":"Older Close Co","period":"2026-08","type":"maintenance"},{"client":"Verb Test Co","period":"2026-09","type":"maintenance"}]');
    }

    public function test_timer_start_and_stop_round_trip(): void
    {
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'P']);
        $task = Task::create(['project_id' => $project->id, 'name' => 'Photo update studio', 'source' => 'manual', 'is_completed' => false]);

        // Start by name fragment; entry opens with no end_time (the dashboard
        // dangling check watches exactly this shape).
        $this->artisan('crm:timer-start', ['task' => 'studio'])->assertSuccessful();

        $entry = \App\Models\TimeEntry::query()->whereNull('end_time')->first();
        $this->assertNotNull($entry);
        $this->assertSame($task->id, $entry->task_id);
        $this->assertSame($this->client->id, $entry->client_id);
        $this->assertTrue((bool) $entry->billable);

        // Single open timer → no argument needed to stop.
        $this->artisan('crm:timer-stop')->assertSuccessful();

        $entry->refresh();
        $this->assertNotNull($entry->end_time);
        $this->assertSame(0, \App\Models\TimeEntry::query()->whereNull('end_time')->count());
    }

    public function test_timer_stop_requires_choice_when_several_run(): void
    {
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'P']);
        $a = Task::create(['project_id' => $project->id, 'name' => 'Alpha', 'source' => 'manual', 'is_completed' => false]);
        $b = Task::create(['project_id' => $project->id, 'name' => 'Beta', 'source' => 'manual', 'is_completed' => false]);
        $this->artisan('crm:timer-start', ['task' => (string) $a->id])->assertSuccessful();
        $this->artisan('crm:timer-start', ['task' => (string) $b->id])->assertSuccessful();

        $this->artisan('crm:timer-stop')->assertFailed();
        $this->artisan('crm:timer-stop', ['entry' => 'Beta'])->assertSuccessful();
        $this->assertSame(1, \App\Models\TimeEntry::query()->whereNull('end_time')->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\ClientBriefTool;
use App\Mcp\Tools\LeadCaptureTool;
use App\Mcp\Tools\LeadStageTool;
use App\Mcp\Tools\TaskDoneTool;
use App\Mcp\Tools\TimerStartTool;
use App\Mcp\Tools\TimerStopTool;
use App\Mcp\Tools\TodayTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MCP mirror of the crm verb contract. Same guarantees as CrmVerbsTest —
 * canonical completion path, append-only histories, model-level guards — plus
 * the two things only the MCP transport adds: structured ambiguity payloads
 * and real user attribution on writes.
 */
final class McpCrmToolsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Verb Test Co']);
    }

    public function test_client_brief_returns_context_as_json(): void
    {
        CrmServer::tool(ClientBriefTool::class, ['client' => (string) $this->client->id])
            ->assertOk()
            ->assertSee('"name":"Verb Test Co"');
    }

    public function test_client_brief_resolves_by_name_fragment(): void
    {
        CrmServer::tool(ClientBriefTool::class, ['client' => 'Verb Test'])
            ->assertOk()
            ->assertSee('Verb Test Co');
    }

    public function test_client_brief_returns_candidates_on_ambiguity(): void
    {
        $second = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Verb Test Two']);

        CrmServer::tool(ClientBriefTool::class, ['client' => 'Verb Test'])
            ->assertHasErrors()
            ->assertSee('ambiguous_reference')
            ->assertSee('"id":'.$this->client->id)
            ->assertSee('"id":'.$second->id);
    }

    public function test_today_returns_the_attention_digest(): void
    {
        CrmServer::tool(TodayTool::class)
            ->assertOk()
            ->assertSee('attention_tasks')
            ->assertSee('month_close_open')
            ->assertSee('hot_leads')
            ->assertSee('running_time_entries');
    }

    public function test_task_done_completes_and_spawns_recurrence(): void
    {
        $task = $this->task(['recurrence_period_days' => 7, 'cli' => 'claude', 'agent_lane' => Task::AGENT_LANE_IN_PROGRESS]);

        CrmServer::tool(TaskDoneTool::class, ['task' => $task->id])
            ->assertOk()
            ->assertSee('"was_already_done":false');

        $task->refresh();
        $this->assertTrue((bool) $task->is_completed);
        $this->assertSame('Done', $task->list_name);
        $this->assertSame(Task::AGENT_LANE_DONE, $task->agent_lane);

        $successor = Task::where('parent_task_id', $task->id)->sole();
        $this->assertSame('claude', $successor->cli);
    }

    public function test_task_done_is_idempotent(): void
    {
        $task = $this->task(['recurrence_period_days' => 7]);

        CrmServer::tool(TaskDoneTool::class, ['task' => $task->id])->assertOk();
        CrmServer::tool(TaskDoneTool::class, ['task' => $task->id])
            ->assertOk()
            ->assertSee('"was_already_done":true');

        // A second completion must not spawn a second successor.
        $this->assertSame(1, Task::where('parent_task_id', $task->id)->count());
    }

    public function test_task_done_reports_an_unknown_task(): void
    {
        CrmServer::tool(TaskDoneTool::class, ['task' => 999999])
            ->assertHasErrors()
            ->assertSee('not_found');
    }

    public function test_add_note_appends_a_journal_entry_attributed_to_the_owner(): void
    {
        $stage = $this->client->lifecycle_stage;

        CrmServer::tool(AddNoteTool::class, ['client' => 'Verb Test', 'note' => 'Called about the rebrand.'])
            ->assertOk();

        $event = $this->client->lifecycleEvents()->latest('id')->first();
        $this->assertSame('Called about the rebrand.', $event->note);
        $this->assertSame($stage, $event->to_stage);
        $this->assertSame($stage, $this->client->fresh()->lifecycle_stage);
        // The CLI verb writes NULL here; the MCP transport knows who is acting.
        $this->assertSame($this->owner->id, $event->user_id);
    }

    public function test_lead_capture_records_the_capture_event(): void
    {
        CrmServer::tool(LeadCaptureTool::class, ['name' => 'Piotr', 'source' => 'referral'])
            ->assertOk()
            ->assertSee('"stage":"new"');

        $lead = Lead::sole();
        $this->assertSame('referral', $lead->source);
        $this->assertSame($this->account->id, $lead->account_id);

        // Capture is a creation, not a transition: from_stage stays null.
        $event = $lead->stageEvents()->sole();
        $this->assertNull($event->from_stage);
    }

    public function test_lead_capture_rejects_an_unknown_source(): void
    {
        CrmServer::tool(LeadCaptureTool::class, ['name' => 'Piotr', 'source' => 'carrier-pigeon'])
            ->assertHasErrors()
            ->assertSee('valid_sources');

        $this->assertSame(0, Lead::count());
    }

    public function test_lead_stage_records_the_hop(): void
    {
        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline' => Lead::pipelines()[0],
            'name' => 'Piotr',
            'source' => 'referral',
        ]);

        CrmServer::tool(LeadStageTool::class, ['lead' => $lead->id, 'stage' => 'conversation', 'note' => 'Replied.'])
            ->assertOk()
            ->assertSee('"stage":"conversation"');

        $this->assertSame('conversation', $lead->fresh()->stage);
        $this->assertSame(2, $lead->stageEvents()->count());
    }

    public function test_lead_stage_rejects_retired_vocabulary(): void
    {
        $lead = Lead::create([
            'account_id' => $this->account->id,
            'pipeline' => Lead::pipelines()[0],
            'name' => 'Piotr',
            'source' => 'referral',
        ]);

        CrmServer::tool(LeadStageTool::class, ['lead' => $lead->id, 'stage' => 'mql'])
            ->assertHasErrors()
            ->assertSee('valid_stages');

        $this->assertSame('new', $lead->fresh()->stage);
    }

    public function test_timer_round_trip(): void
    {
        $task = $this->task(['name' => 'Hero work']);

        CrmServer::tool(TimerStartTool::class, ['task' => 'Hero'])
            ->assertOk()
            ->assertSee('"task_id":'.$task->id);

        $entry = TimeEntry::sole();
        $this->assertNull($entry->end_time);

        CrmServer::tool(TimerStopTool::class)
            ->assertOk()
            ->assertSee('"entry_id":'.$entry->id);

        $this->assertNotNull($entry->fresh()->end_time);
    }

    public function test_timer_stop_lists_candidates_when_several_run(): void
    {
        $first = $this->task(['name' => 'Hero work']);
        $second = $this->task(['name' => 'Footer work']);

        CrmServer::tool(TimerStartTool::class, ['task' => (string) $first->id])->assertOk();
        CrmServer::tool(TimerStartTool::class, ['task' => (string) $second->id])->assertOk();

        CrmServer::tool(TimerStopTool::class)
            ->assertHasErrors()
            ->assertSee('ambiguous_reference')
            ->assertSee('Hero work')
            ->assertSee('Footer work');

        // Naming one of them resolves it.
        CrmServer::tool(TimerStopTool::class, ['entry' => 'Footer'])->assertOk();

        $this->assertSame(1, TimeEntry::whereNull('end_time')->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): Task
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'P'.uniqid(),
        ]);

        return Task::create([
            'project_id' => $project->id,
            'name' => 'Weekly check',
            'source' => 'manual',
            'is_completed' => false,
            ...$attributes,
        ]);
    }
}

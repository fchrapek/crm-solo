<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\ClientBriefTool;
use App\Mcp\Tools\LeadCaptureTool;
use App\Mcp\Tools\LeadStageTool;
use App\Mcp\Tools\MonthCloseOverviewTool;
use App\Mcp\Tools\MonthSettlementPreviewTool;
use App\Mcp\Tools\ReportsTool;
use App\Mcp\Tools\ReportTool;
use App\Mcp\Tools\TaskDoneTool;
use App\Mcp\Tools\TimerStartTool;
use App\Mcp\Tools\TimerStopTool;
use App\Mcp\Tools\TodayTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Lead;
use App\Models\MonthCloseRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\TestCase;

/**
 * The MCP mirror of the crm verb contract. Same guarantees as CrmVerbsTest —
 * canonical completion path, append-only histories, model-level guards — plus
 * the two things only the MCP transport adds: structured ambiguity payloads
 * and real user attribution on writes.
 */
final class McpCrmToolsTest extends TestCase
{
    use CallsAgentSurfaces;
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

    public function test_client_brief_says_month_hours_are_all_logged_time_and_the_limit_is_the_bank(): void
    {
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01', 'is_active' => false]);

        CrmServer::tool(ClientBriefTool::class, ['client' => (string) $this->client->id])
            ->assertOk()
            ->assertSee('"month_counts":"all_logged_time"')
            ->assertSee('"bank":{"counts":"reportable_time"')
            ->assertSee('"has_retainer":false')
            ->assertSee('"report_status":null');
    }

    public function test_an_order_clients_brief_has_no_hours_bank(): void
    {
        CrmServer::tool(ClientBriefTool::class, ['client' => (string) $this->client->id])
            ->assertOk()
            ->assertSee('"billing_mode":"order"')
            ->assertSee('"contracted":null,"bank":null');
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

    public function test_today_includes_a_close_still_open_for_the_previous_month(): void
    {
        $this->travelTo('2026-10-03 09:00:00');
        MonthCloseRun::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'period' => '2026-09',
            'close_type' => 'maintenance',
            'status' => 'open',
        ]);

        CrmServer::tool(TodayTool::class)
            ->assertOk()
            ->assertSee('"period":"2026-09"');
    }

    public function test_task_done_completes_and_spawns_recurrence(): void
    {
        $task = $this->task(['recurrence_period_days' => 7]);

        CrmServer::tool(TaskDoneTool::class, ['task' => $task->id])
            ->assertOk()
            ->assertSee('"was_already_done":false');

        $task->refresh();
        $this->assertTrue((bool) $task->is_completed);
        $this->assertSame('Done', $task->list_name);

        $successor = Task::where('parent_task_id', $task->id)->sole();
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

    public function test_month_close_overview_shape(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true, 'report_mode' => MonthCloseRun::REPORT_MODE_REPORT]);
        MonthCloseRun::startFor($this->client, '2026-09');

        [$ok, $payload] = $this->tool(MonthCloseOverviewTool::class);
        $row = $payload['clients'][0];

        $this->assertTrue($ok);
        $this->assertSame(['period', 'clients'], array_keys($payload));
        $this->assertSame(['client_id', 'client', 'close_type', 'in_close', 'checklist', 'report', 'settlement'], array_keys($row));
        $this->assertSame(['status', 'resolved', 'total', 'steps'], array_keys($row['checklist']));
        $this->assertSame(['key', 'project', 'state', 'position', 'note'], array_keys($row['checklist']['steps'][0]));
        $this->assertSame(['mode', 'report_id', 'status'], array_keys($row['report']));
    }

    public function test_report_and_reports_shapes(): void
    {
        $report = $this->monthReport();

        [$ok, $one] = $this->tool(ReportTool::class, ['report' => $report->id]);
        [, $list] = $this->tool(ReportsTool::class, ['client' => 'Verb Test']);

        $summary = ['id', 'period', 'period_type', 'period_start', 'period_end', 'whole_month', 'status', 'governs', 'needs_recalculation', 'composer_key', 'generated_at', 'finalized_at', 'sent_at', 'sent_on', 'edit_url'];
        $this->assertTrue($ok);
        $this->assertSame([...$summary, 'client_id', 'client', 'hours', 'revision_count', 'version', 'body_markdown', 'untrusted'], array_keys($one));
        $this->assertSame(['contracted', 'used', 'available', 'opening', 'forfeited', 'closing', 'change'], array_keys($one['hours']));
        $this->assertSame(['client_id', 'client', 'total', 'reports'], array_keys($list));
        $this->assertSame($summary, array_keys($list['reports'][0]));
    }

    public function test_month_settlement_preview_shape(): void
    {
        $this->travelTo('2026-10-20 10:00:00');

        [$ok, $payload] = $this->tool(MonthSettlementPreviewTool::class, ['client' => 'Verb Test', 'period' => '2026-09']);

        $this->assertTrue($ok);
        $this->assertSame(['client_id', 'client', 'period', 'next', 'contracted', 'opening', 'cap', 'forfeited', 'used', 'closing', 'source', 'report_status', 'entries', 'hash', 'refusal'], array_keys($payload));
    }

    public function test_report_takes_a_report_id_or_a_client_not_both(): void
    {
        $report = $this->monthReport();

        CrmServer::tool(ReportTool::class, ['report' => $report->id, 'client' => 'Verb Test'])->assertHasErrors();
        CrmServer::tool(ReportTool::class, [])->assertHasErrors();
    }

    private function monthReport(): ClientReport
    {
        return ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_DRAFT,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'contracted_hours' => 20,
            'actual_hours' => 12,
            'opening_balance_hours' => 0,
            'currency' => 'PLN',
            'composer_key' => 'structured_list',
            'body_markdown' => 'Work done.',
            'generated_at' => now(),
        ]);
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\ClientBriefTool;
use App\Mcp\Tools\ClientConfigTool;
use App\Mcp\Tools\LeadCaptureTool;
use App\Mcp\Tools\LeadStageTool;
use App\Mcp\Tools\LogTimeTool;
use App\Mcp\Tools\MonthCloseStatusTool;
use App\Mcp\Tools\MonthCloseTickTool;
use App\Mcp\Tools\TaskDoneTool;
use App\Mcp\Tools\TimerStartTool;
use App\Mcp\Tools\TimerStopTool;
use App\Mcp\Tools\TodayTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\Lead;
use App\Models\MonthCloseRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Every agent verb acts inside the acting identity's account. A record in
 * another account is never returned and never changed: the caller gets a
 * reference error, exactly as if the record did not exist.
 */
final class AgentAccountScopingTest extends TestCase
{
    use RefreshDatabase;

    private Account $mine;

    private User $owner;

    private Client $otherClient;

    private Project $otherProject;

    private Task $otherTask;

    private TimeEntry $otherTimer;

    private Lead $otherLead;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leadgen.tiers.gold_min' => 0]);

        // The identity resolves to the first account and its owner.
        $this->mine = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $this->mine->id, 'owner' => true]);
        $mineClient = Client::factory()->create(['account_id' => $this->mine->id, 'name' => 'Mine Co']);
        $mineProject = Project::create(['account_id' => $this->mine->id, 'client_id' => $mineClient->id, 'name' => 'General']);
        Task::create(['project_id' => $mineProject->id, 'name' => 'Mine urgent', 'source' => 'manual', 'priority' => 'high', 'list_name' => 'To-Do']);

        $other = Account::factory()->create();
        $this->otherClient = Client::factory()->create(['account_id' => $other->id, 'name' => 'Other Co', 'month_close_type' => 'maintenance']);
        $this->otherProject = Project::create(['account_id' => $other->id, 'client_id' => $this->otherClient->id, 'name' => 'Other site']);
        $this->otherTask = Task::create([
            'project_id' => $this->otherProject->id, 'name' => 'Secret task', 'source' => 'manual',
            'priority' => 'high', 'due_date' => now()->subDays(3)->toDateString(), 'list_name' => 'To-Do',
        ]);
        $this->otherTimer = TimeEntry::startFor($this->otherTask);
        $this->otherLead = Lead::create([
            'account_id' => $other->id, 'pipeline' => Lead::pipelines()[0], 'name' => 'Other lead', 'source' => Lead::sources()[0],
        ]);
        MonthCloseRun::create(['account_id' => $other->id, 'client_id' => $this->otherClient->id, 'period' => '2026-09', 'close_type' => 'maintenance', 'status' => 'open']);
    }

    public function test_today_shows_only_the_acting_account(): void
    {
        Artisan::call('crm:today', ['--json' => true]);
        $this->assertStringContainsString('Mine urgent', Artisan::output());
        $this->assertLeaksNothing(Artisan::output());

        $this->assertLeaksNothing($this->toolText(TodayTool::class));
    }

    public function test_brief_cannot_read_another_account(): void
    {
        $this->assertCliNotFound('crm:brief', ['client' => (string) $this->otherClient->id, '--json' => true]);
        $this->assertCliNotFound('crm:brief', ['client' => 'Other', '--json' => true]);
        $this->assertToolNotFound(ClientBriefTool::class, ['client' => (string) $this->otherClient->id]);
    }

    public function test_client_config_cannot_read_another_account(): void
    {
        $this->assertCliNotFound('client:config', ['client' => (string) $this->otherClient->id, '--json' => true]);
        $this->assertToolNotFound(ClientConfigTool::class, ['client' => (string) $this->otherClient->id]);
    }

    public function test_task_done_cannot_finish_another_accounts_task(): void
    {
        $this->assertCliNotFound('crm:task-done', ['task' => (string) $this->otherTask->id, '--json' => true]);
        $this->assertToolNotFound(TaskDoneTool::class, ['task' => $this->otherTask->id]);

        $this->assertNull($this->otherTask->fresh()->finished_at);
        $this->assertNull($this->otherTimer->fresh()->end_time);
    }

    public function test_timer_start_cannot_start_on_another_accounts_task(): void
    {
        $this->assertCliNotFound('crm:timer-start', ['task' => (string) $this->otherTask->id, '--json' => true]);
        $this->assertCliNotFound('crm:timer-start', ['task' => 'Secret', '--json' => true]);
        $this->assertToolNotFound(TimerStartTool::class, ['task' => (string) $this->otherTask->id]);

        $this->assertSame(1, TimeEntry::count());
    }

    public function test_timer_stop_cannot_stop_another_accounts_timer(): void
    {
        $this->assertCliNotFound('crm:timer-stop', ['entry' => (string) $this->otherTimer->id, '--json' => true]);
        $this->assertCliNotFound('crm:timer-stop', ['--json' => true]);
        $this->assertToolNotFound(TimerStopTool::class, ['entry' => (string) $this->otherTimer->id]);
        $this->assertToolNotFound(TimerStopTool::class, []);

        $this->assertNull($this->otherTimer->fresh()->end_time);
    }

    public function test_note_cannot_write_to_another_accounts_timeline(): void
    {
        $before = $this->otherClient->lifecycleEvents()->count();

        $this->assertCliNotFound('crm:note', ['client' => (string) $this->otherClient->id, 'note' => 'x', '--json' => true]);
        $this->assertToolNotFound(AddNoteTool::class, ['client' => (string) $this->otherClient->id, 'note' => 'x']);

        $this->assertSame($before, $this->otherClient->lifecycleEvents()->count());
    }

    public function test_lead_stage_cannot_move_another_accounts_lead(): void
    {
        $stage = $this->otherLead->stage;
        $target = collect(Lead::rowStages((string) $this->otherLead->pipeline))->last();

        $this->assertCliNotFound('crm:lead-stage', ['lead' => (string) $this->otherLead->id, 'stage' => $target, '--json' => true]);
        $this->assertToolNotFound(LeadStageTool::class, ['lead' => $this->otherLead->id, 'stage' => $target]);

        $this->assertSame($stage, $this->otherLead->fresh()->stage);
    }

    public function test_lead_capture_writes_into_the_acting_account(): void
    {
        Artisan::call('crm:lead-capture', ['--name' => 'Cli lead', '--source' => Lead::sources()[0], '--json' => true]);
        CrmServer::tool(LeadCaptureTool::class, ['name' => 'Mcp lead', 'source' => Lead::sources()[0]])->assertOk();

        $this->assertSame([$this->mine->id, $this->mine->id], Lead::whereIn('name', ['Cli lead', 'Mcp lead'])->pluck('account_id')->all());
    }

    public function test_time_log_cannot_log_against_another_account(): void
    {
        foreach (['--task' => $this->otherTask->id, '--client' => $this->otherClient->id, '--project' => $this->otherProject->id] as $option => $id) {
            $this->assertSame(1, Artisan::call('time:log', ['minutes' => 15, $option => (string) $id]));
            $this->assertStringContainsString('No ', Artisan::output());
        }
        $this->assertSame(1, Artisan::call('time:log', ['minutes' => 15, '--client' => 'Mine', '--account' => (string) $this->otherClient->account_id]));

        foreach (['task' => $this->otherTask->id, 'client' => $this->otherClient->id, 'project' => $this->otherProject->id] as $target => $id) {
            $this->assertToolNotFound(LogTimeTool::class, ['minutes' => 15, $target => (string) $id]);
        }

        $this->assertSame(1, TimeEntry::count());
    }

    public function test_month_close_cannot_read_or_tick_another_account(): void
    {
        $this->assertCliNotFound('month-close:tick', ['client' => (string) $this->otherClient->id, 'step' => 'list', '--json' => true]);
        $this->assertCliNotFound('month-close:tick', ['client' => (string) $this->otherClient->id, 'step' => 'report', 'state' => 'done', '--period' => '2026-09', '--json' => true]);
        $this->assertToolNotFound(MonthCloseStatusTool::class, ['client' => (string) $this->otherClient->id]);
        $this->assertToolNotFound(MonthCloseTickTool::class, ['client' => (string) $this->otherClient->id, 'step' => 'report', 'state' => 'done', 'period' => '2026-09']);

        $this->assertSame(0, MonthCloseRun::sole()->steps()->where('state', 'done')->count());
    }

    public function test_the_demo_prompt_acts_as_the_signed_in_visitors_account(): void
    {
        config(['app.demo' => true]);
        $visitor = User::factory()->create(['account_id' => $this->otherClient->account_id]);

        $output = (string) $this->actingAs($visitor)->postJson('/demo/cli', ['command' => 'crm today --json'])->assertOk()->json('output');

        $this->assertStringContainsString('Secret task', $output);
        $this->assertStringNotContainsString('Mine urgent', $output);
    }

    private function assertLeaksNothing(string $output): void
    {
        $this->assertStringNotContainsString('Secret task', $output);
        $this->assertStringNotContainsString('Other Co', $output);
        $this->assertStringNotContainsString('Other lead', $output);
        $this->assertStringNotContainsString('"id":'.$this->otherTimer->id.',', $output);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function assertCliNotFound(string $command, array $arguments): void
    {
        $this->assertSame(1, Artisan::call($command, $arguments), $command.' should fail');
        $payload = json_decode(mb_trim(Artisan::output()), true);
        $this->assertSame('not_found', $payload['error'] ?? null, $command.' should answer not_found');
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    private function assertToolNotFound(string $tool, array $arguments): void
    {
        CrmServer::tool($tool, $arguments)->assertHasErrors()->assertSee('not_found');
    }

    /**
     * @param  class-string  $tool
     */
    private function toolText(string $tool): string
    {
        $response = CrmServer::tool($tool)->assertOk();
        $property = new ReflectionProperty($response, 'response');

        return (string) json_encode($property->getValue($response)->toArray());
    }
}

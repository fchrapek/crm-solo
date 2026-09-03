<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\MonthCloseStatusTool;
use App\Mcp\Tools\MonthCloseTickTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonthCloseRun;
use App\Models\MonthCloseStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The month close driven from chat. The checklist is the deliverable, so the
 * read tool must stay a read: peeking at a period that was never opened must
 * not open it.
 */
final class McpMonthCloseToolsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Client $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->site = Client::factory()->create([
            'account_id' => $this->account->id,
            'name' => 'Maintained Site',
            'month_close_type' => MonthCloseRun::TYPE_MAINTENANCE,
            'report_mode' => 'report',
        ]);

        // One site in the close, so the site steps are seeded and a bare step
        // key stays unambiguous.
        $this->site->projects()->create([
            'account_id' => $this->account->id,
            'name' => 'maintained.example',
            'include_in_month_close' => true,
        ]);
    }

    public function test_status_previews_the_template_without_starting_the_run(): void
    {
        CrmServer::tool(MonthCloseStatusTool::class, ['client' => 'Maintained', 'period' => '2026-06'])
            ->assertOk()
            ->assertSee('"status":"not_started"')
            ->assertSee('db_archived');

        // A status read must never open a close.
        $this->assertSame(0, MonthCloseRun::count());
    }

    public function test_status_refuses_a_client_not_in_monthly_close(): void
    {
        $other = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Not Ours']);

        CrmServer::tool(MonthCloseStatusTool::class, ['client' => (string) $other->id])
            ->assertHasErrors()
            ->assertSee('not in monthly close');
    }

    public function test_first_tick_seeds_the_checklist_and_attributes_the_step(): void
    {
        CrmServer::tool(MonthCloseTickTool::class, [
            'client' => 'Maintained',
            'step' => 'db_archived',
            'state' => 'done',
            'period' => '2026-06',
        ])->assertOk()->assertSee('"status":"open"');

        $run = MonthCloseRun::sole();
        $this->assertSame('2026-06', $run->period);
        $this->assertSame(MonthCloseRun::TYPE_MAINTENANCE, $run->close_type);

        $step = $run->steps()->where('step_key', 'db_archived')->sole();
        $this->assertSame(MonthCloseStep::STATE_DONE, $step->state);
        $this->assertNotNull($step->completed_at);
        // The CLI verb writes NULL here; MCP knows who ticked it.
        $this->assertSame($this->owner->id, $step->completed_by);
    }

    public function test_unknown_step_lists_the_available_keys(): void
    {
        CrmServer::tool(MonthCloseTickTool::class, [
            'client' => (string) $this->site->id,
            'step' => 'polish_the_logo',
            'state' => 'done',
            'period' => '2026-06',
        ])->assertHasErrors()->assertSee('db_archived');
    }

    public function test_a_second_site_makes_a_bare_step_key_ambiguous(): void
    {
        $this->site->projects()->create([
            'account_id' => $this->account->id,
            'name' => 'second.example',
            'include_in_month_close' => true,
        ]);

        CrmServer::tool(MonthCloseTickTool::class, [
            'client' => (string) $this->site->id,
            'step' => 'wp_updates',
            'state' => 'done',
            'period' => '2026-06',
        ])->assertHasErrors()->assertSee('second.example');

        CrmServer::tool(MonthCloseTickTool::class, [
            'client' => (string) $this->site->id,
            'step' => 'wp_updates',
            'state' => 'done',
            'project' => 'second',
            'period' => '2026-06',
        ])->assertOk();

        $done = MonthCloseRun::sole()->steps()
            ->where('step_key', 'wp_updates')
            ->where('state', MonthCloseStep::STATE_DONE)
            ->with('project:id,name')
            ->sole();

        $this->assertSame('second.example', $done->project?->name);
    }

    public function test_invalid_state_is_rejected(): void
    {
        CrmServer::tool(MonthCloseTickTool::class, [
            'client' => (string) $this->site->id,
            'step' => 'db_archived',
            'state' => 'nearly',
            'period' => '2026-06',
        ])->assertHasErrors()->assertSee('invalid_argument');
    }

    public function test_resolving_every_step_completes_the_run_and_pending_reopens_it(): void
    {
        $run = MonthCloseRun::startFor($this->site, '2026-06');
        $keys = $run->steps()->pluck('step_key');

        foreach ($keys as $key) {
            CrmServer::tool(MonthCloseTickTool::class, [
                'client' => (string) $this->site->id,
                'step' => $key,
                'state' => 'done',
                'period' => '2026-06',
            ])->assertOk();
        }

        $this->assertSame(MonthCloseRun::STATUS_COMPLETED, $run->fresh()->status);

        CrmServer::tool(MonthCloseTickTool::class, [
            'client' => (string) $this->site->id,
            'step' => $keys->first(),
            'state' => 'pending',
            'period' => '2026-06',
        ])->assertOk()->assertSee('"status":"open"');

        $this->assertSame(MonthCloseRun::STATUS_OPEN, $run->fresh()->status);
        $reopened = $run->steps()->where('step_key', $keys->first())->sole();
        $this->assertNull($reopened->completed_at);
        $this->assertNull($reopened->completed_by);
    }

    public function test_period_defaults_to_the_previous_month(): void
    {
        $expected = now()->subMonthNoOverflow()->format('Y-m');

        CrmServer::tool(MonthCloseStatusTool::class, ['client' => (string) $this->site->id])
            ->assertOk()
            ->assertSee('"period":"'.$expected.'"');
    }

    public function test_invalid_period_is_rejected(): void
    {
        CrmServer::tool(MonthCloseStatusTool::class, ['client' => (string) $this->site->id, 'period' => 'June'])
            ->assertHasErrors()
            ->assertSee('YYYY-MM');
    }
}

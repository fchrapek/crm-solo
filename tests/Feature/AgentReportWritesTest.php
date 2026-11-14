<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportRevision;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Agents write reports through the services the report page uses: a draft is
 * created once per month, its body and figures change only while it is a
 * draft, and every write stays inside the token's account and leaves the
 * logged time alone.
 */
final class AgentReportWritesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-07 10:00:00');
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Roofs Ltd', 'month_close_type' => 'maintenance', 'include_in_month_close' => true]);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'General']);
        $this->client->retainers()->create(['account_id' => $account->id, 'monthly_hours' => 10, 'monthly_fee' => 220, 'currency' => 'PLN', 'effective_from' => '2025-01-01']);
        TimeEntry::create([
            'account_id' => $account->id, 'project_id' => $project->id, 'client_id' => $this->client->id,
            'start_time' => '2026-09-10 08:00:00', 'end_time' => '2026-09-10 11:00:00', 'duration_minutes' => 180, 'source' => 'manual', 'billable' => true,
        ]);
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::REPORTS]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_agent_creates_the_draft_once_and_a_second_one_for_the_month_is_refused_naming_the_first(): void
    {
        $time = $this->timeRows();

        $first = $this->create()->assertOk()->assertHeader('X-Crm-Exit', '0')->json();
        $this->assertSame('draft', $first['report']['status']);
        $this->assertSame('2026-09', $first['report']['period']);

        $this->create()->assertHeader('X-Crm-Exit', '1')->assertSee('report_exists')->assertSee((string) $first['report']['id']);
        $this->assertSame(1, ClientReport::query()->count());
        $this->assertSame($time, $this->timeRows());
        $this->assertSame('crm:report-create', AgentAuditEvent::query()->where('target_type', 'client_reports')->sole()->verb);
    }

    public function test_a_body_edit_needs_the_current_version_and_is_stored_humanized_with_a_revision(): void
    {
        $report = $this->draft();
        $version = ClientReport::query()->findOrFail($report)->version();

        $this->verb('report-update', [(string) $report, '--body=Stale', '--version=old', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('refused');
        $this->assertSame(0, ClientReportRevision::query()->count());

        $this->verb('report-update', [(string) $report, "--body=A \u{201C}quoted\u{201D} line \u{2014} done", "--version={$version}", '--json'])->assertHeader('X-Crm-Exit', '0');

        $this->assertSame('A "quoted" line - done', ClientReport::query()->findOrFail($report)->body_markdown);
        $this->assertSame(1, ClientReportRevision::query()->count());
    }

    public function test_a_finalized_report_takes_no_body_or_balance_change_until_it_is_reopened(): void
    {
        $report = $this->draft();

        $this->verb('report-status', [(string) $report, 'finalize', '--json'])->assertHeader('X-Crm-Exit', '0');
        $version = ClientReport::query()->findOrFail($report)->version();
        $this->verb('report-update', [(string) $report, '--body=New', "--version={$version}", '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('not_a_draft');
        $this->verb('report-opening-balance', [(string) $report, '2', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('not_a_draft');
        $this->verb('report-regenerate', [(string) $report, '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('not_a_draft');

        $this->verb('report-status', [(string) $report, 'sent', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->assertSame('sent', ClientReport::query()->findOrFail($report)->status);
        $this->verb('report-status', [(string) $report, 'reopen', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->verb('report-opening-balance', [(string) $report, '2', '--json'])->assertHeader('X-Crm-Exit', '0');

        $fresh = ClientReport::query()->findOrFail($report);
        $this->assertSame(['draft', 2.0], [$fresh->status, (float) $fresh->opening_balance_hours]);
    }

    public function test_the_mcp_tools_write_the_same_way_and_say_to_confirm_first(): void
    {
        $tools = collect($this->mcp('tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        foreach (['report_create', 'report_update', 'report_regenerate', 'report_opening_balance', 'report_status'] as $name) {
            $this->assertStringContainsString('wait for the owner', $tools[$name]['description']);
        }

        $this->mcp('tools/call', ['name' => 'report_create', 'arguments' => ['client' => 'Roofs', 'period' => '2026-09', 'composer' => 'structured_list']])
            ->assertOk()->assertJsonPath('result.isError', false);
        $report = ClientReport::query()->sole();
        $this->mcp('tools/call', ['name' => 'report_status', 'arguments' => ['report' => (string) $report->id, 'action' => 'finalize']])
            ->assertOk()->assertJsonPath('result.isError', false);

        $this->assertSame('finalized', $report->fresh()->status);
        $verbs = AgentAuditEvent::query()->where('target_type', 'client_reports')->pluck('verb')->unique()->values()->all();
        $this->assertSame(['report_create', 'report_status'], $verbs);
    }

    public function test_a_token_without_write_reports_reads_but_cannot_write_and_another_account_cannot_see_the_report(): void
    {
        $report = $this->draft();

        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::MONTH_CLOSE]);
        $this->verb('report', [(string) $report, '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');
        $this->verb('report-status', [(string) $report, 'finalize'])->assertForbidden();

        $stranger = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::REPORTS], $stranger);
        $this->verb('report-status', [(string) $report, 'finalize', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('not_found');

        $this->assertSame('draft', ClientReport::query()->findOrFail($report)->status);
    }

    public function test_an_unknown_composer_is_refused_with_the_valid_keys_and_writes_nothing(): void
    {
        $this->verb('report-create', ['Roofs', '--period=2026-09', '--composer=nope', '--json'])
            ->assertHeader('X-Crm-Exit', '1')->assertSee('invalid_argument')->assertSee('structured_list');

        $this->assertSame(0, ClientReport::query()->count());
    }

    private function create(): TestResponse
    {
        return $this->verb('report-create', ['Roofs', '--period=2026-09', '--composer=structured_list', '--json']);
    }

    private function draft(): int
    {
        return (int) $this->create()->assertHeader('X-Crm-Exit', '0')->json('report.id');
    }

    /** @return list<array<string, mixed>> */
    private function timeRows(): array
    {
        return DB::table('time_entries')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    /** @param  list<string>  $abilities */
    private function token(array $abilities, ?User $user = null): string
    {
        $user ??= $this->owner;
        $issued = $user->createToken('agent', $abilities, now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $user->account_id])->save();

        return $issued->plainTextToken;
    }

    /** @param  list<string>  $args */
    private function verb(string $verb, array $args = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->plain])->post('/agent/verb', ['verb' => $verb, 'args' => $args]);
    }

    /** @param  array<string, mixed>  $params */
    private function mcp(string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->plain])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }
}

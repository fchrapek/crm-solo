<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\MonthCloseOverviewTool;
use App\Mcp\Tools\MonthSettlementPreviewTool;
use App\Mcp\Tools\ReportsTool;
use App\Mcp\Tools\ReportTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\MonthCloseRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\Feature\Settlement\SettlementFixtures;
use Tests\TestCase;

/**
 * Agents read the month close the way the pages show it: the worklist, a client's reports with the Rozliczenia
 * figures, and what closing a month would freeze. Every read stays inside the acting account and writes nothing.
 */
final class AgentMonthCloseReadsTest extends TestCase
{
    use CallsAgentSurfaces;
    use RefreshDatabase;
    use SettlementFixtures;

    private const array FIGURES = ['contracted', 'used', 'available', 'opening', 'forfeited', 'closing', 'change'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement(baseline: '2026-08', nowUtc: '2026-10-20 10:00:00');
        config(['agent.local_account' => $this->account->id]);
        $this->retainer(30, '2026-01-01');
    }

    public function test_the_overview_lists_a_client_whose_close_has_not_started_without_starting_it(): void
    {
        $gig = $this->account->clients()->create(['name' => 'Gig Studio', 'month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);

        [$exit, $verb] = $this->verb('crm:month-close');
        [$ok, $tool] = $this->tool(MonthCloseOverviewTool::class);

        $this->assertSame([0, true], [$exit, $ok]);
        $this->assertSame($verb, $tool);
        $this->assertSame('2026-09', $verb['period']);
        $row = collect($verb['clients'])->firstWhere('client_id', $gig->id);
        $this->assertSame(['not_started', 0, 3], [$row['checklist']['status'], $row['checklist']['resolved'], $row['checklist']['total']]);
        $this->assertSame(['reconcile_log', 'report', 'draft_invoice'], array_column($row['checklist']['steps'], 'key'));
        $this->assertSame(['mode' => 'report', 'report_id' => null, 'status' => null], $row['report']);
        $this->assertNull($row['settlement'], 'no retainer, nothing to settle');
        $this->assertSame(0, MonthCloseRun::query()->count());
    }

    public function test_the_overview_shows_the_period_run_report_and_financial_close(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true, 'report_mode' => MonthCloseRun::REPORT_MODE_REPORT]);
        MonthCloseRun::startFor($this->client, '2026-09');
        $this->report('2026-08', opening: 0, contracted: 30, actual: 20, status: ClientReport::STATUS_SENT);
        $september = $this->report('2026-09', opening: 10, contracted: 30, actual: 25, status: ClientReport::STATUS_FINALIZED);
        $this->settleDirectly('2026-08');

        [, $overview] = $this->verb('crm:month-close', ['--period' => '2026-09']);
        $row = collect($overview['clients'])->firstWhere('client_id', $this->client->id);

        $this->assertSame(['open', 0, 3], [$row['checklist']['status'], $row['checklist']['resolved'], $row['checklist']['total']]);
        $this->assertSame(['mode' => 'report', 'report_id' => $september->id, 'status' => 'finalized'], $row['report']);
        $this->assertEquals(['state' => 'ready', 'active_period' => '2026-09', 'closed_on' => null, 'closing' => 15.0], $row['settlement']);
    }

    public function test_the_overview_answers_for_the_period_asked(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true, 'report_mode' => MonthCloseRun::REPORT_MODE_REPORT]);
        MonthCloseRun::startFor($this->client, '2026-09');
        $this->report('2026-09', opening: 0, contracted: 30, actual: 25);

        [, $august] = $this->tool(MonthCloseOverviewTool::class, ['period' => '2026-08']);
        $row = collect($august['clients'])->firstWhere('client_id', $this->client->id);

        $this->assertSame('2026-08', $august['period']);
        $this->assertSame('not_started', $row['checklist']['status']);
        $this->assertNull($row['report']['report_id']);
        $this->assertSame('ready', $row['settlement']['state'], 'August is the oldest month still owed');
        $this->assertSame(1, MonthCloseRun::query()->count());

        [$exit, $refused] = $this->verb('crm:month-close', ['--period' => 'September']);
        $this->assertSame([1, 'invalid_argument'], [$exit, $refused['error']]);
    }

    public function test_the_overview_lists_the_clients_the_month_close_page_lists(): void
    {
        $this->account->clients()->create(['name' => 'Gig Studio', 'month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);
        $this->account->clients()->create(['name' => 'Left Out', 'month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => false]);

        $page = collect($this->actingAs($this->user)->get('/month-close?period=2026-09')->assertOk()->viewData('page')['props']['clients'])->all();
        [, $overview] = $this->verb('crm:month-close', ['--period' => '2026-09']);

        $this->assertSame(array_map(fn (array $row): int => $row['client']['id'], $page), array_column($overview['clients'], 'client_id'));
        $this->assertSame(
            array_map(fn (array $row): ?array => $row['settlement'] !== null ? Arr::except($row['settlement'], ['period']) : null, $page),
            array_column($overview['clients'], 'settlement'),
        );
    }

    public function test_report_hours_equal_the_rozliczenia_row_for_its_month(): void
    {
        $this->report('2026-08', opening: 0, contracted: 30, actual: 34, status: ClientReport::STATUS_SENT);
        $september = $this->report('2026-09', opening: -4, contracted: 30, actual: 12.5);
        $this->entry('2026-09-10 08:00:00', 600);

        $rows = collect($this->actingAs($this->user)->get("/clients/{$this->client->id}/rozliczenia")->assertOk()->viewData('page')['props']['billing_ledger']['rows'])->keyBy('period');
        [$exit, $byId] = $this->verb('crm:report', ['report' => (string) $september->id]);
        [, $byClient] = $this->verb('crm:report', ['report' => (string) $this->client->id, '--period' => '2026-09']);
        [$ok, $tool] = $this->tool(ReportTool::class, ['client' => 'Acme', 'period' => '2026-09']);

        $this->assertSame([0, true], [$exit, $ok]);
        $this->assertEquals(Arr::only($rows['2026-09'], self::FIGURES), $byId['hours']);
        $this->assertSame(self::FIGURES, array_keys($byId['hours']));
        $this->assertSame($byId, $byClient);
        $this->assertSame($byId, $tool);
        $this->assertSame([$september->id, '2026-09', 'draft', true, 0], [$byId['id'], $byId['period'], $byId['status'], $byId['governs'], $byId['revision_count']]);
        $this->assertSame("# 2026-09\n\nWork done.", $byId['body_markdown']);
        $this->assertSame(['body_markdown'], $byId['untrusted']);
        $this->assertStringEndsWith("/clients/{$this->client->id}/reports/{$september->id}", $byId['edit_url']);

        [, $august] = $this->tool(ReportTool::class, ['client' => 'Acme', 'period' => '2026-08']);
        $this->assertEquals(Arr::only($rows['2026-08'], self::FIGURES), $august['hours']);
    }

    public function test_a_client_without_a_report_for_the_month_gets_not_found_and_an_empty_list(): void
    {
        [$exit, $verb] = $this->verb('crm:report', ['report' => 'Acme', '--period' => '2026-09']);
        [$ok, $tool] = $this->tool(ReportTool::class, ['client' => 'Acme']);
        [, $list] = $this->tool(ReportsTool::class, ['client' => 'Acme']);

        $this->assertSame([1, false], [$exit, $ok]);
        $this->assertSame(['not_found', 'report'], [$verb['error'], $verb['entity']]);
        $this->assertSame(['not_found', 'report'], [$tool['error'], $tool['entity']]);
        $this->assertSame(['client_id' => $this->client->id, 'client' => 'Acme', 'total' => 0, 'reports' => []], $list);
    }

    public function test_reports_come_newest_first_without_bodies_and_name_the_one_the_bank_reads(): void
    {
        $august = $this->report('2026-08', opening: 0, contracted: 30, actual: 20, status: ClientReport::STATUS_SENT);
        $draft = $this->report('2026-09', opening: 10, contracted: 30, actual: 25);
        $final = $this->report('2026-09', opening: 10, contracted: 30, actual: 25, status: ClientReport::STATUS_FINALIZED);

        [$exit, $verb] = $this->verb('crm:reports', ['client' => 'Acme']);
        [, $tool] = $this->tool(ReportsTool::class, ['client' => (string) $this->client->id]);

        $this->assertSame(0, $exit);
        $this->assertSame($verb, $tool);
        $this->assertSame([$final->id, $draft->id, $august->id], array_column($verb['reports'], 'id'));
        $this->assertSame([true, false, true], array_column($verb['reports'], 'governs'));
        $this->assertArrayNotHasKey('body_markdown', $verb['reports'][0]);
    }

    public function test_the_settlement_preview_matches_the_web_preview_and_closes_nothing(): void
    {
        $this->report('2026-08', opening: 0, contracted: 30, actual: 20, status: ClientReport::STATUS_SENT);
        $this->entry('2026-08-10 08:00:00', 600);

        $web = $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/months/2026-08/close")->assertOk()->json();
        [$exit, $verb] = $this->verb('crm:month-settle', ['client' => 'Acme', 'period' => '2026-08', '--preview' => true]);
        [$ok, $tool] = $this->tool(MonthSettlementPreviewTool::class, ['client' => 'Acme', 'period' => '2026-08']);

        $this->assertSame([0, true], [$exit, $ok]);
        $this->assertSame(['client_id' => $this->client->id, ...$web], $verb);
        $this->assertSame($verb, $tool);
        $this->assertSame(40, mb_strlen($verb['hash']));

        [$exit, $refused] = $this->verb('crm:month-settle', ['client' => 'Acme', 'period' => '2026-08']);
        $this->assertSame([1, 'invalid_argument'], [$exit, $refused['error']]);
        $this->assertSame(0, ClientMonthClosure::query()->count());
    }

    public function test_a_name_fragment_matching_several_clients_lists_the_candidates(): void
    {
        $second = $this->account->clients()->create(['name' => 'Acme Two']);

        foreach ([['crm:reports', ['client' => 'Acme']], ['crm:report', ['report' => 'Acme']], ['crm:month-settle', ['client' => 'Acme', 'period' => '2026-09', '--preview' => true]]] as [$command, $arguments]) {
            [$exit, $payload] = $this->verb($command, $arguments);
            $this->assertSame([1, 'ambiguous_reference'], [$exit, $payload['error']], $command);
            $this->assertEqualsCanonicalizing([$this->client->id, $second->id], array_column($payload['candidates'], 'id'), $command);
        }
        foreach ([[ReportsTool::class, ['client' => 'Acme']], [ReportTool::class, ['client' => 'Acme']], [MonthSettlementPreviewTool::class, ['client' => 'Acme', 'period' => '2026-09']]] as [$tool, $arguments]) {
            [$ok, $payload] = $this->tool($tool, $arguments);
            $this->assertSame([false, 'ambiguous_reference'], [$ok, $payload['error']], $tool);
        }
    }

    public function test_every_read_stays_inside_the_acting_account(): void
    {
        $other = Account::factory()->create();
        User::factory()->create(['account_id' => $other->id, 'owner' => true]);
        $stranger = Client::factory()->create(['account_id' => $other->id, 'name' => 'Other Co', 'month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true, 'report_mode' => MonthCloseRun::REPORT_MODE_REPORT]);
        $this->retainer(20, '2026-01-01', client: $stranger);
        MonthCloseRun::startFor($stranger, '2026-09');
        $theirs = $this->report('2026-09', opening: 0, contracted: 20, actual: 5, client: $stranger);

        [, $overview] = $this->verb('crm:month-close');
        [, $toolOverview] = $this->tool(MonthCloseOverviewTool::class);
        $this->assertNotContains($stranger->id, array_column($overview['clients'], 'client_id'));
        $this->assertNotContains($stranger->id, array_column($toolOverview['clients'], 'client_id'));

        foreach ([
            ['crm:report', ['report' => (string) $theirs->id]],
            ['crm:report', ['report' => 'Other', '--period' => '2026-09']],
            ['crm:reports', ['client' => (string) $stranger->id]],
            ['crm:month-settle', ['client' => (string) $stranger->id, 'period' => '2026-09', '--preview' => true]],
        ] as [$command, $arguments]) {
            [$exit, $payload] = $this->verb($command, $arguments);
            $this->assertSame([1, 'not_found'], [$exit, $payload['error']], $command);
        }
        foreach ([
            [ReportTool::class, ['report' => $theirs->id]],
            [ReportTool::class, ['client' => (string) $stranger->id]],
            [ReportsTool::class, ['client' => 'Other']],
            [MonthSettlementPreviewTool::class, ['client' => (string) $stranger->id, 'period' => '2026-09']],
        ] as [$tool, $arguments]) {
            [$ok, $payload] = $this->tool($tool, $arguments);
            $this->assertSame([false, 'not_found'], [$ok, $payload['error']], $tool);
        }
    }

    public function test_the_reads_refuse_while_billing_is_switched_off(): void
    {
        $this->account->update(['settings' => [...$this->account->settings, 'features' => ['billing' => false]]]);
        $report = $this->report('2026-09', opening: 0, contracted: 30, actual: 5);

        foreach ([['crm:month-close', []], ['crm:report', ['report' => (string) $report->id]], ['crm:reports', ['client' => 'Acme']], ['crm:month-settle', ['client' => 'Acme', 'period' => '2026-09', '--preview' => true]]] as [$command, $arguments]) {
            [$exit, $payload] = $this->verb($command, $arguments);
            $this->assertSame([1, 'feature_off'], [$exit, $payload['error']], $command);
        }
    }

    public function test_the_text_output_prints_each_read_and_fences_the_report_body(): void
    {
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true, 'report_mode' => MonthCloseRun::REPORT_MODE_REPORT]);
        $report = $this->report('2026-09', opening: 0, contracted: 30, actual: 5);

        $this->assertSame(0, Artisan::call('crm:month-close'));
        $this->assertStringContainsString("#{$this->client->id} Acme · checklist not_started 0/3 · report: report #{$report->id} draft", Artisan::output());
        $this->assertSame(0, Artisan::call('crm:report', ['report' => (string) $report->id]));
        $this->assertMatchesRegularExpression('/BEGIN EXTERNAL CONTENT \w+ .*\n# 2026-09\n\nWork done.\n===== END EXTERNAL CONTENT/s', Artisan::output());
        $this->assertSame(0, Artisan::call('crm:reports', ['client' => 'Acme']));
        $this->assertStringContainsString("#{$report->id} 2026-09-01 to 2026-09-30 · draft", Artisan::output());
        $this->assertSame(0, Artisan::call('crm:month-settle', ['client' => 'Acme', 'period' => '2026-08', '--preview' => true]));
        $this->assertStringContainsString('hash ', Artisan::output());
    }

    private function settleDirectly(string $period): void
    {
        $this->closure($period, opening: 0, contracted: 30, used: 20);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\ClientBriefTool;
use App\Models\ClientReport;
use App\Services\Agent\ClientBrief;
use App\Services\Clients\MonthSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class BriefSettlementTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement(nowUtc: '2026-11-20 10:00:00');
        $this->retainer(30, '2026-01-01');
        $this->entry('2026-09-10 08:00:00', 600);
        $this->entry('2026-11-05 08:00:00', 90, reportable: false);
    }

    public function test_the_bank_is_the_month_being_settled_while_month_stays_the_calendar_month(): void
    {
        $hours = app(ClientBrief::class)->for($this->client)['hours'];

        $this->assertSame(1.5, $hours['month']);
        $this->assertSame('2026-09', $hours['bank']['period']);
        $this->assertSame('2026-09', $hours['bank']['active_period']);
        $this->assertTrue($hours['bank']['open_period_behind']);
        $this->assertSame('entries', $hours['bank']['source']);
        $this->assertSame(10.0, $hours['bank']['used']);
        $this->assertNull($hours['bank']['closed_period']);
        $this->assertNull($hours['bank']['closed_at']);
    }

    public function test_after_a_close_the_bank_moves_on_and_names_the_closed_month(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $settlement = app(MonthSettlement::class);
        $settlement->close($this->client, '2026-09', $settlement->preview($this->client, '2026-09')['hash'], $this->user);

        $bank = app(ClientBrief::class)->for($this->client)['hours']['bank'];

        $this->assertSame('2026-10', $bank['active_period']);
        $this->assertSame(20.0, $bank['carried']);
        $this->assertTrue($bank['open_period_behind']);
        $this->assertSame('2026-09', $bank['closed_period']);
        $this->assertSame('2026-11-20T10:00:00+00:00', $bank['closed_at']);
    }

    public function test_the_latest_report_is_the_one_the_bank_reads_when_two_end_the_same_day(): void
    {
        $sent = $this->report('2026-10', opening: 0, contracted: 30, actual: 5, status: ClientReport::STATUS_SENT);
        $this->report('2026-10', opening: 0, contracted: 30, actual: 9);

        $this->assertSame($sent->id, app(ClientBrief::class)->for($this->client)['latest_report']['id']);
    }

    public function test_the_cli_and_the_mcp_tool_say_which_month_the_bank_is(): void
    {
        $this->artisan('crm:brief', ['client' => (string) $this->client->id])
            ->expectsOutputToContain('Hours logged this month: 1.5 (all time)')
            ->expectsOutputToContain('Hours bank (reportable, 2026-09, an elapsed month still open): 10 used of 30 available')
            ->assertSuccessful();

        $this->actingAs($this->user);
        CrmServer::tool(ClientBriefTool::class, ['client' => (string) $this->client->id])
            ->assertSee('"active_period":"2026-09"')
            ->assertSee('"open_period_behind":true');
    }
}

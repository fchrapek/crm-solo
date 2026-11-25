<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The month Billing shows carries its own settlement, so a month past the loaded pages of the bank still closes and reopens. */
final class BillingMonthSettlementTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement('2026-08');
        $this->retainer(30, '2026-01-01');
    }

    public function test_a_month_that_owes_a_close_reads_open_and_names_no_closure(): void
    {
        $this->shown('2026-08', fn (Assert $page) => $page
            ->where('billing_month.settlement.state', 'open')
            ->where('billing_month.settlement.closure_id', null));
    }

    public function test_only_the_latest_closed_month_names_its_closure_to_reopen(): void
    {
        $august = $this->settle('2026-08');
        $september = $this->settle('2026-09');

        $this->shown('2026-09', fn (Assert $page) => $page
            ->where('billing_month.settlement.state', 'closed')
            ->where('billing_month.settlement.closure_id', $september->id));
        $this->shown('2026-08', fn (Assert $page) => $page
            ->where('billing_month.settlement.state', 'closed')
            ->where('billing_month.settlement.closure_id', null));
        $this->assertNotSame($august->id, $september->id);
    }

    public function test_a_month_before_the_settlement_start_owes_no_close(): void
    {
        $this->shown('2026-06', fn (Assert $page) => $page->where('billing_month.settlement.state', null));
    }

    private function shown(string $period, Closure $assert): void
    {
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/billing?month={$period}")->assertInertia(fn (Assert $page) => $assert($page));
    }

    private function settle(string $period): \App\Models\ClientMonthClosure
    {
        $settlement = app(MonthSettlement::class);
        $result = $settlement->close($this->client, $period, $settlement->preview($this->client, $period)['hash'], $this->user);
        $this->assertSame(SettlementOutcome::Applied, $result->outcome, $result->message);

        return $result->closure;
    }
}

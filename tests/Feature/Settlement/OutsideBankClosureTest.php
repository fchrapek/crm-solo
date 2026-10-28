<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\ClientMonthClosure;
use App\Services\Clients\HoursBank;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OutsideBankClosureTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
    }

    public function test_closing_a_month_whose_report_has_no_contracted_hours_closes_at_its_opening(): void
    {
        $this->report('2026-09', opening: 0, contracted: null, actual: 2);
        $this->retainer(10, '2026-09-01');
        $settlement = app(MonthSettlement::class);

        $preview = $settlement->preview($this->client, '2026-09');
        $this->assertSame(0.0, $preview['snapshot']['closing']);

        $result = $settlement->close($this->client, '2026-09', $preview['hash'], $this->user);
        $this->assertSame(SettlementOutcome::Applied, $result->outcome);
        $this->assertSame(0.0, ClientMonthClosure::query()->sole()->closingHours());

        $bank = app(HoursBank::class);
        $september = $bank->month($this->client, '2026-09');
        $this->assertSame(0.0, $september->closingBalanceHours(), 'the closed month hydrates at its opening');
        $october = $bank->month($this->client, '2026-10');
        $this->assertSame(0.0, $october->openingBalanceHours);
        $this->assertSame(10.0, $october->availableHours());
    }
}

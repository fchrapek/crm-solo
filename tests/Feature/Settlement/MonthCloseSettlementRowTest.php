<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\ClientMonthClosure;
use App\Models\MonthCloseRun;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The month-close page's financial close row, beside the checklist and never one of its steps. */
final class MonthCloseSettlementRowTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(30, '2026-01-01');
    }

    public function test_the_month_close_page_gets_a_financial_row_per_retainer_client_and_period(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $this->client->update(['month_close_type' => MonthCloseRun::TYPE_MAINTENANCE, 'include_in_month_close' => true]);
        $plain = $this->account->clients()->create(['name' => 'Bez Abonamentu', 'month_close_type' => MonthCloseRun::TYPE_GIG, 'include_in_month_close' => true]);
        $this->entry('2026-09-10 08:00:00', 600);

        $row = fn (string $period, int $clientId): ?array => collect($this->monthClose($period))->firstWhere('client.id', $clientId)['settlement'];

        $this->assertSame(['state' => 'ready', 'period' => '2026-09', 'active_period' => '2026-09', 'closed_on' => null, 'closing' => 20.0], $row('2026-09', $this->client->id));
        $this->assertSame('blocked', $row('2026-10', $this->client->id)['state']);
        $this->assertNull($row('2026-11', $this->client->id), 'the month in progress has no close');
        $this->assertNull($row('2026-08', $this->client->id), 'before the baseline nothing is owed');
        $this->assertNull($row('2026-09', $plain->id));

        $this->settle('2026-09');

        $this->assertSame(['closed', '2026-11-20'], [$row('2026-09', $this->client->id)['state'], $row('2026-09', $this->client->id)['closed_on']]);
        $this->assertSame('ready', $row('2026-10', $this->client->id)['state']);
        $this->assertSame(0, MonthCloseRun::query()->count(), 'the financial close is no checklist step');
    }

    private function monthClose(string $period): array
    {
        return $this->actingAs($this->user)->get("/month-close?period={$period}")->assertOk()->viewData('page')['props']['clients'];
    }

    private function settle(string $period): ClientMonthClosure
    {
        $settlement = app(MonthSettlement::class);
        $result = $settlement->close($this->client, $period, $settlement->preview($this->client, $period)['hash'], $this->user);
        $this->assertSame(SettlementOutcome::Applied, $result->outcome, $result->message);

        return $result->closure;
    }
}

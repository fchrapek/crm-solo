<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\Account;
use App\Models\ClientMonthClosure;
use App\Models\ClientReport;
use App\Models\User;
use App\Services\Clients\MonthSettlement;
use App\Services\Clients\SettlementOutcome;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The confirm dialog's preview and what Przegląd shows of a month left open. */
final class SettlementDialogTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(30, '2026-01-01');
    }

    public function test_the_preview_answers_the_figures_a_close_would_freeze_with_the_hash_it_checks(): void
    {
        $this->entry('2026-09-10 08:00:00', 36 * 60 + 30);
        $this->report('2026-09', opening: -9.5, contracted: 30, actual: 32, status: ClientReport::STATUS_FINALIZED);

        $preview = $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/months/2026-09/close")->assertOk()->json();

        $this->assertSame('Acme', $preview['client']);
        $this->assertSame('2026-10', $preview['next']);
        $this->assertEquals([30, -9.5, 32, -11.5, 36.5], [$preview['contracted'], $preview['opening'], $preview['used'], $preview['closing'], $preview['entries']]);
        $this->assertSame(['report', 'finalized', null], [$preview['source'], $preview['report_status'], $preview['refusal']]);
        $this->assertSame(app(MonthSettlement::class)->preview($this->client, '2026-09')['hash'], $preview['hash']);

        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/przeglad")
            ->post("/clients/{$this->client->id}/months/2026-09/close", ['preview_hash' => $preview['hash']])
            ->assertSessionHas('settlement', ['action' => 'closed', 'period' => '2026-09', 'closing' => -11.5, 'next' => '2026-10', 'recalculate' => null]);
    }

    public function test_the_preview_carries_the_refusal_so_the_dialog_can_disable_its_button(): void
    {
        $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/months/2026-10/close")
            ->assertOk()
            ->assertJsonPath('refusal', 'The month in progress cannot be closed.');

        $this->entry('2026-09-30 08:00:00', 0, running: true);
        $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/months/2026-09/close")
            ->assertJsonPath('refusal', 'Stop the timer running for September 2026 first.');
    }

    public function test_the_preview_is_scoped_to_the_account_and_to_real_months(): void
    {
        $stranger = User::factory()->create(['account_id' => Account::create(['name' => 'Other'])->id, 'owner' => true]);

        $this->actingAs($stranger)->getJson("/clients/{$this->client->id}/months/2026-09/close")->assertNotFound();
        $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/months/2026-13/close")->assertNotFound();
    }

    public function test_przeglad_shows_the_open_month_behind_and_moves_on_once_it_is_closed(): void
    {
        $this->entry('2026-09-10 08:00:00', 12 * 60);
        $this->entry('2026-10-02 08:00:00', 60);

        $this->overview(fn (Assert $page) => $page
            ->where('overview.hours.period', '2026-09')
            ->where('overview.hours.used', 12)
            ->where('overview.settlement', ['active_period' => '2026-09', 'current_period' => '2026-10', 'open_behind' => true]));

        $this->settle('2026-09');

        $this->overview(fn (Assert $page) => $page
            ->where('overview.hours.period', '2026-10')
            ->where('overview.hours.carried', 18)
            ->where('overview.settlement.open_behind', false));
    }

    public function test_przeglad_leaves_a_closed_month_even_while_its_report_still_waits(): void
    {
        $this->report('2026-09', opening: 0, contracted: 30, actual: 20);
        $this->settle('2026-09');

        $this->overview(fn (Assert $page) => $page
            ->where('overview.report_due.period', '2026-09')
            ->where('overview.hours.period', '2026-10'));
    }

    private function overview(Closure $assert): void
    {
        $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")->assertInertia(fn (Assert $page) => $assert($page));
    }

    private function settle(string $period): ClientMonthClosure
    {
        $settlement = app(MonthSettlement::class);
        $result = $settlement->close($this->client, $period, $settlement->preview($this->client, $period)['hash'], $this->user);
        $this->assertSame(SettlementOutcome::Applied, $result->outcome, $result->message);

        return $result->closure;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientMonthClosure;
use App\Models\ClientMonthSettlementEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class SettlementSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_without_the_setting_starts_settling_in_its_local_creation_month(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-31 23:30:00', 'UTC'));

        $account = Account::create(['name' => 'Fresh']);

        $this->assertSame('2026-11', $account->settlementFrom());

        $this->travelTo(Carbon::parse('2027-03-10 12:00:00', 'UTC'));
        $this->assertSame('2026-11', $account->fresh()->settlementFrom(), 'never recomputed from the clock');
    }

    public function test_the_setting_wins_and_a_malformed_one_falls_back(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'UTC'));

        $this->assertSame('2026-09', Account::create(['name' => 'Set', 'settings' => ['settlement_from' => '2026-09']])->settlementFrom());
        $this->assertSame('2026-10', Account::create(['name' => 'Bad', 'settings' => ['settlement_from' => '2026-13']])->settlementFrom());
    }

    public function test_the_backfill_sets_the_literal_baseline_and_keeps_other_settings(): void
    {
        $this->travelTo(Carbon::parse('2026-12-15 12:00:00', 'UTC'));
        $bare = Account::create(['name' => 'Bare']);
        $withSettings = Account::create(['name' => 'Other', 'settings' => ['report_prompt' => 'x']]);
        $alreadySet = Account::create(['name' => 'Set', 'settings' => ['settlement_from' => '2026-11']]);

        $migration = require database_path('migrations/2026_10_05_200000_backfill_settlement_from_on_accounts.php');
        $migration->up();

        $this->assertSame('2026-09', $bare->fresh()->settlementFrom());
        $this->assertSame(['report_prompt' => 'x', 'settlement_from' => '2026-09'], $withSettings->fresh()->settings);
        $this->assertSame('2026-11', $alreadySet->fresh()->settlementFrom());

        $migration->down();

        $this->assertNull($bare->fresh()->settings);
        $this->assertSame(['report_prompt' => 'x'], $withSettings->fresh()->settings);
        $this->assertSame('2026-11', $alreadySet->fresh()->settlementFrom());
    }

    public function test_the_settlement_migrations_go_down_and_up_again(): void
    {
        $files = [
            '2026_10_05_200000_backfill_settlement_from_on_accounts.php',
            '2026_10_05_200100_create_client_month_closures_table.php',
            '2026_10_05_200200_create_client_month_settlement_events_table.php',
            '2026_10_05_200300_add_needs_recalculation_to_client_reports.php',
        ];
        $migrations = array_map(fn (string $file) => require database_path("migrations/{$file}"), $files);

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('client_month_closures'));
        $this->assertFalse(Schema::hasTable('client_month_settlement_events'));
        $this->assertFalse(Schema::hasColumn('client_reports', 'needs_recalculation'));

        foreach ($migrations as $migration) {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('client_month_closures'));
        $this->assertTrue(Schema::hasTable('client_month_settlement_events'));
        $this->assertTrue(Schema::hasColumn('client_reports', 'needs_recalculation'));
    }

    public function test_a_settlement_event_refuses_to_change_or_disappear(): void
    {
        $event = $this->event();

        try {
            $event->update(['action' => ClientMonthSettlementEvent::ACTION_REOPEN]);
            $this->fail('An event was updated.');
        } catch (LogicException) {
        }

        try {
            $event->delete();
            $this->fail('An event was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame(ClientMonthSettlementEvent::ACTION_CLOSE, $event->fresh()->action);
    }

    public function test_a_client_has_one_closure_per_month(): void
    {
        $client = $this->client();
        $row = ['client_id' => $client->id, 'period' => '2026-09', 'closed_at' => now(), 'snapshot' => ['schema_version' => 1]];
        ClientMonthClosure::create($row);

        $this->expectException(QueryException::class);
        ClientMonthClosure::create($row);
    }

    public function test_a_report_starts_without_the_recalculation_flag(): void
    {
        $client = $this->client();
        $report = $client->reports()->create([
            'account_id' => $client->account_id, 'period_type' => 'month', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'actual_hours' => 0, 'currency' => 'PLN', 'composer_key' => 'manual', 'body_markdown' => 'x', 'status' => 'draft',
        ]);

        $this->assertFalse($report->fresh()->needs_recalculation);
    }

    private function client(): Client
    {
        return Account::create(['name' => 'Acme'])->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
    }

    private function event(): ClientMonthSettlementEvent
    {
        return ClientMonthSettlementEvent::create([
            'client_id' => $this->client()->id,
            'period' => '2026-09',
            'action' => ClientMonthSettlementEvent::ACTION_CLOSE,
            'closure_id' => 1,
            'snapshot' => ['schema_version' => 1],
            'created_at' => now(),
        ]);
    }
}

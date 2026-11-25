<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\User;
use App\Services\Reports\BaselineSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Report baseline on Billing: an account setting, off by default, switched in Settings, Account. */
final class ReportBaselineSettingTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Acme']);
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create([
            'name' => 'Pracownia Testowa',
            'currency' => 'PLN',
            'report_baseline_markdown' => "### W ramach abonamentu\n\n- monitoring\n- aktualizacje\n  - wtyczki\n\n### Poza abonamentem\n\nWycena osobno.",
        ]);
    }

    public function test_a_new_account_has_it_off_so_billing_carries_no_baseline(): void
    {
        $this->assertFalse($this->account->showsReportBaseline());

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page->where('billing.report_baseline', null));
    }

    public function test_with_it_on_billing_carries_the_text_and_one_section_per_heading_with_its_items(): void
    {
        $this->account->update(['settings' => ['report_baseline' => true]]);
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 30, 'effective_from' => '2026-01-01']);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page
                ->where('billing.mode', 'retainer')
                ->where('billing.report_baseline.markdown', $this->client->report_baseline_markdown)
                ->where('billing.report_baseline.sections', [
                    ['title' => 'W ramach abonamentu', 'items' => 2],
                    ['title' => 'Poza abonamentem', 'items' => 0],
                ]));
    }

    public function test_an_order_client_carries_it_too(): void
    {
        $this->account->update(['settings' => ['report_baseline' => true]]);

        $this->actingAs($this->owner)->get("/clients/{$this->client->id}/billing")
            ->assertInertia(fn (Assert $page) => $page->where('billing.mode', 'order')->has('billing.report_baseline.sections', 2));
    }

    public function test_sections_without_a_heading_and_an_empty_text(): void
    {
        $sections = new BaselineSections;

        $this->assertSame([], $sections->of(null));
        $this->assertSame([], $sections->of("  \n"));
        $this->assertSame([['title' => null, 'items' => 3]], $sections->of("- a\n- b\n- c"));
        $this->assertSame([['title' => null, 'items' => 0]], $sections->of('Tylko akapit.'));
        $this->assertSame([['title' => null, 'items' => 1], ['title' => 'Druga', 'items' => 1]], $sections->of("- a\n\n## Druga\n\n1. b"));
    }

    public function test_the_owner_switches_it_in_general_and_a_member_cannot(): void
    {
        $this->actingAs($this->owner)->get('/settings/general')
            ->assertInertia(fn (Assert $page) => $page->where('modules.report_baseline', false));

        $this->actingAs($this->owner)->from('/settings/general')->patch('/settings/modules', ['report_baseline' => true])
            ->assertRedirect('/settings/general');
        $this->assertTrue($this->account->fresh()->showsReportBaseline());

        $member = User::factory()->create(['account_id' => $this->account->id, 'owner' => false]);
        $this->actingAs($member)->get('/settings/general')->assertNotFound();
        $this->actingAs($member)->patch('/settings/modules', ['report_baseline' => false])->assertForbidden();
        $this->assertTrue($this->account->fresh()->showsReportBaseline());
    }

    public function test_the_switch_keeps_the_accounts_other_settings(): void
    {
        $this->account->update(['settings' => ['settlement_from' => '2026-01', 'features' => ['billing' => true]]]);

        $this->actingAs($this->owner)->patch('/settings/modules', ['report_baseline' => true]);

        $this->assertSame(['settlement_from' => '2026-01', 'features' => ['billing' => true], 'report_baseline' => true], $this->account->fresh()->settings);
    }

    public function test_the_migration_turns_it_on_only_for_accounts_that_wrote_a_baseline(): void
    {
        $quiet = Account::create(['name' => 'Bez zakresu', 'settings' => ['settlement_from' => '2026-02']]);
        $quiet->clients()->create(['name' => 'Klient bez zakresu', 'currency' => 'PLN']);
        DB::table('accounts')->where('id', $this->account->id)->update(['settings' => json_encode(['settlement_from' => '2026-01'])]);

        $migration = require database_path('migrations/2026_11_21_200000_turn_on_report_baseline_where_one_is_written.php');
        $migration->up();

        $this->assertTrue($this->account->fresh()->showsReportBaseline());
        $this->assertSame('2026-01', $this->account->fresh()->settings['settlement_from']);
        $this->assertFalse($quiet->fresh()->showsReportBaseline());
        $this->assertSame(['settlement_from' => '2026-02'], $quiet->fresh()->settings);

        $migration->down();
        $this->assertSame(['settlement_from' => '2026-01'], $this->account->fresh()->settings);
    }
}

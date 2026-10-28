<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientRetainer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\HoursBank;
use App\Services\Reports\Composers\StructuredListComposer;
use App\Services\Reports\ReportComposerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ClientBillingTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

    private Project $project;

    private ClientRetainer $dormant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        // A position switched off keeps the retainer layout without a pool in any month.
        $this->dormant = ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'label' => 'Wygaszona', 'monthly_hours' => 1, 'effective_from' => '2020-01-01', 'is_active' => false]);

        $this->app->bind(ReportComposerRegistry::class, function ($app): ReportComposerRegistry {
            $registry = new ReportComposerRegistry;
            $registry->register($app->make(StructuredListComposer::class), default: true);

            return $registry;
        });
    }

    public function test_only_the_billing_tab_computes_the_billing(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/rozliczenia")
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->where('tab', 'rozliczenia')
                ->has('billing_ledger.rows')
                ->has('billing.retainer')
                ->has('billing.invoices'));

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (Assert $page) => $page->missing('billing'));
    }

    public function test_the_ledger_lists_each_report_newest_first_with_its_state(): void
    {
        $this->report('2026-07', ClientReport::STATUS_SENT, opening: -21, contracted: 30, actual: 24, sentAt: '2026-08-03 08:00:00');
        $this->report('2026-08', ClientReport::STATUS_FINALIZED, opening: -15, contracted: 30, actual: 30.5);
        $this->report('2026-09', ClientReport::STATUS_DRAFT, opening: -15.5, contracted: 30, actual: 32);

        $billing = $this->billing();
        $rows = $billing['ledger']['rows'];

        $this->assertSame(['2026-09', '2026-08', '2026-07'], array_column($rows, 'period'));
        $this->assertSame(['draft', 'finalized', 'sent'], array_column($rows, 'status'));
        $this->assertSame([null, null, '2026-08-03'], array_column($rows, 'sent_on'));
        $this->assertEquals([-17.5, -15.5, -15.0], array_column($rows, 'closing'));
        $this->assertEquals([-2.0, -0.5, 6.0], array_column($rows, 'change'));
        $this->assertEquals([32.0, 30.5, 24.0], array_column($rows, 'used'));
        $this->assertSame(['date' => '2026-07-01', 'balance' => -21.0], $billing['ledger']['start']);
        $this->assertSame(['report_id' => $rows[1]['report_id'], 'period' => '2026-08', 'status' => 'finalized'], $billing['waiting_report']);
    }

    public function test_every_whole_month_row_reads_exactly_as_the_hours_bank(): void
    {
        $this->retainer(10, cap: 12);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Work', 'is_reportable' => true]);
        $this->entry($task, '2026-10-02 08:00:00', 150);
        $this->report('2026-08', ClientReport::STATUS_SENT, opening: 5, contracted: 10, actual: 2, cap: 12);
        $this->report('2026-09', ClientReport::STATUS_FINALIZED, opening: 10, contracted: 10, actual: 14.25, cap: 12);
        $this->report('2026-10', ClientReport::STATUS_DRAFT, opening: -2.25, contracted: 10, actual: 9, cap: 12);

        $bank = app(HoursBank::class);
        foreach ($this->billing()['ledger']['rows'] as $row) {
            $month = $bank->month($this->client, $row['period']);
            $this->assertSame($month->reportId, $row['report_id']);
            $this->assertSame($month->contractedHours, $row['contracted']);
            $this->assertSame($month->openingBalanceHours, $row['opening']);
            $this->assertSame($month->usedHours, $row['used']);
            $this->assertSame($month->closingBalanceHours(), $row['closing']);
            $this->assertSame($month->forfeitedHours(), $row['forfeited']);
        }

        $october = $this->billing()['ledger']['rows'][0];
        $this->assertSame(2.5, $october['used'], 'a draft for the running month counts the live reportable time');
        $this->assertFalse($october['ended']);
    }

    public function test_reports_sharing_a_period_show_once_as_the_bank_reads_them(): void
    {
        $this->report('2026-09', ClientReport::STATUS_DRAFT, actual: 1);
        $sent = $this->report('2026-09', ClientReport::STATUS_SENT, actual: 2, sentAt: '2026-10-01 09:00:00');
        $this->report('2026-09', ClientReport::STATUS_FINALIZED, actual: 3);

        $rows = $this->billing()['ledger']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame($sent->id, $rows[0]['report_id']);
        $this->assertSame(2.0, $rows[0]['used']);
    }

    public function test_a_partial_period_keeps_its_own_snapshot_and_dates(): void
    {
        ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_SENT,
            'period_start' => '2026-06-16',
            'period_end' => '2026-06-30',
            'contracted_hours' => 5,
            'actual_hours' => 3,
            'opening_balance_hours' => 0,
            'composer_key' => 'manual',
        ]);

        $row = $this->billing()['ledger']['rows'][0];

        $this->assertFalse($row['whole_month']);
        $this->assertSame('2026-06-16', $row['period_start']);
        $this->assertSame(2.0, $row['closing']);
    }

    public function test_a_legacy_partial_draft_ending_with_a_sent_month_never_becomes_the_period_the_bank_reads(): void
    {
        $sent = $this->report('2026-09', ClientReport::STATUS_SENT, opening: 1, actual: 4);
        ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_DRAFT,
            'period_start' => '2026-09-16',
            'period_end' => '2026-09-30',
            'actual_hours' => 2,
            'opening_balance_hours' => 0,
            'composer_key' => 'manual',
        ]);

        $billing = $this->billing();

        $this->assertSame([$sent->id], array_column($billing['ledger']['rows'], 'report_id'));
        $this->assertSame($sent->id, $billing['opening_balance']['report_id']);
        $this->assertSame(7.0, $billing['ledger']['rows'][0]['closing']);
        $this->assertSame(app(HoursBank::class)->openingBalance($this->client, Carbon::parse('2026-10-01')), $billing['ledger']['rows'][0]['closing']);
    }

    public function test_a_newer_partial_report_of_the_same_status_never_outranks_the_whole_month_ending_with_it(): void
    {
        $whole = $this->report('2026-09', ClientReport::STATUS_SENT, opening: 0, contracted: 10, actual: 3);
        $partial = $this->report('2026-09', ClientReport::STATUS_SENT, opening: 0, contracted: 10, actual: 8);
        $partial->update(['period_start' => '2026-09-16']);

        $billing = $this->billing();

        $this->assertSame([$whole->id], array_column($billing['ledger']['rows'], 'report_id'));
        $this->assertSame($whole->id, $billing['opening_balance']['report_id']);
        $this->assertSame(7.0, $billing['ledger']['rows'][0]['closing']);
        $this->assertSame(app(HoursBank::class)->openingBalance($this->client, Carbon::parse('2026-10-01')), $billing['ledger']['rows'][0]['closing']);
    }

    public function test_an_unsent_report_older_than_the_ledger_shows_still_waits(): void
    {
        $oldest = $this->report('2025-09', ClientReport::STATUS_FINALIZED);
        foreach (range(1, 12) as $offset) {
            $this->report(Carbon::parse('2025-09-01')->addMonths($offset)->format('Y-m'), ClientReport::STATUS_SENT, sentAt: '2026-10-01 08:00:00');
        }

        $billing = $this->billing();

        $this->assertCount(12, $billing['ledger']['rows']);
        $this->assertSame('2', $billing['ledger']['next']);
        $this->assertSame(['report_id' => $oldest->id, 'period' => '2025-09', 'status' => 'finalized'], $billing['waiting_report']);
    }

    public function test_without_a_report_or_a_retainer_the_bank_is_empty_and_has_no_opening_balance_to_change(): void
    {
        $billing = $this->billing();

        $this->assertSame([], $billing['ledger']['rows']);
        $this->assertNull($billing['ledger']['start']);
        $this->assertNull($billing['opening_balance']);
        $this->assertNull($billing['waiting_report']);
    }

    public function test_the_opening_balance_to_change_is_the_newest_reports_and_the_patch_moves_its_row(): void
    {
        $this->report('2026-08', ClientReport::STATUS_SENT, opening: 0, contracted: 10, actual: 8);
        $september = $this->report('2026-09', ClientReport::STATUS_DRAFT, opening: 2, contracted: 10, actual: 11);

        $this->assertSame(['report_id' => $september->id, 'value' => 2.0, 'version' => $september->version()], $this->billing()['opening_balance']);

        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/rozliczenia")
            ->patch("/clients/{$this->client->id}/reports/{$september->id}/opening-balance", ['opening_balance_hours' => -4])
            ->assertRedirect("/clients/{$this->client->id}/rozliczenia");

        $row = $this->billing()['ledger']['rows'][0];
        $this->assertSame(-4.0, $row['opening']);
        $this->assertSame(-5.0, $row['closing']);
        $this->assertSame(1, $september->revisions()->count());
    }

    public function test_an_opening_balance_change_on_a_report_edited_since_the_page_loaded_is_refused(): void
    {
        $september = $this->report('2026-09', ClientReport::STATUS_DRAFT, opening: 2, contracted: 10, actual: 11);
        $target = $this->billing()['opening_balance'];
        $this->assertSame($september->version(), $target['version']);

        $september->update(['body_markdown' => "## Podsumowanie rozliczeniowe\n\n- edited elsewhere"]);

        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/rozliczenia")
            ->patch("/clients/{$this->client->id}/reports/{$september->id}/opening-balance", ['opening_balance_hours' => -4, 'version' => $target['version']])
            ->assertRedirect("/clients/{$this->client->id}/rozliczenia")
            ->assertSessionHas('error');

        $this->assertSame(2.0, (float) $september->fresh()->opening_balance_hours);
        $this->assertStringContainsString('edited elsewhere', $september->fresh()->body_markdown);
    }

    public function test_the_opening_value_and_version_come_from_one_read_so_a_change_landing_between_reads_is_not_overwritten(): void
    {
        $september = $this->report('2026-09', ClientReport::STATUS_DRAFT, opening: 2, contracted: 10, actual: 11);
        $changed = false;
        ClientReport::retrieved(function (ClientReport $report) use ($september, &$changed): void {
            if (! $changed && $report->id === $september->id) {
                $changed = true;
                DB::table('client_reports')->where('id', $september->id)->update(['opening_balance_hours' => 9]);
            }
        });

        $target = $this->billing()['opening_balance'];
        $this->assertSame(2.0, $target['value']);

        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/rozliczenia")
            ->patch("/clients/{$this->client->id}/reports/{$september->id}/opening-balance", ['opening_balance_hours' => 3, 'version' => $target['version']])
            ->assertSessionHas('error');

        $this->assertSame(9.0, (float) $september->fresh()->opening_balance_hours);
    }

    public function test_the_retainer_block_lists_todays_positions_leading_first(): void
    {
        $hosting = $this->retainer(0, fee: 190);
        $care = $this->retainer(8, cap: 16, fee: 1200, overage: 165);
        $ended = ClientRetainer::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'monthly_hours' => 20,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-10-01',
        ]);

        $retainer = $this->billing()['retainer'];

        $this->assertSame([$care->id, $hosting->id], array_column($retainer['active'], 'id'));
        $this->assertSame([$care->id, $hosting->id], $retainer['active_ids']);
        $this->assertSame(['id' => $care->id, 'label' => null, 'monthly_hours' => 8.0, 'monthly_fee' => 1200.0, 'rollover_cap_hours' => 16.0, 'currency' => 'PLN'], $retainer['active'][0]);
        $this->assertEqualsCanonicalizing([$hosting->id, $care->id, $ended->id, $this->dormant->id], array_column($retainer['positions'], 'id'));
        $this->assertSame(16.0, $this->billing()['cap']);
    }

    public function test_invoices_carry_their_status_payment_and_infakt_link(): void
    {
        config(['services.infakt.invoice_url' => 'https://app.infakt.pl/app/faktury/{id}']);
        $this->invoice('1/07/2026', 'paid', '2026-07-05', '2026-07-31', payment: '2026-07-19', paid: '2026-07-15');
        $this->invoice('1/08/2026', 'sent', '2026-08-05', '2026-08-31', payment: '2026-08-19');
        $this->invoice('1/09/2026', 'sent', '2026-10-01', '2026-09-30', payment: '2026-10-15');

        $invoices = $this->billing()['invoices'];

        $this->assertSame(['1/09/2026', '1/08/2026', '1/07/2026'], array_column($invoices['rows'], 'number'));
        $this->assertSame([false, true, false], array_column($invoices['rows'], 'overdue'));
        $this->assertSame([null, null, '2026-07-15'], array_column($invoices['rows'], 'paid_date'));
        $this->assertSame('https://app.infakt.pl/app/faktury/ext-1/07/2026', $invoices['rows'][2]['external_url']);
        $this->assertSame(1800.0, $invoices['rows'][0]['net']);
        $this->assertSame(2214.0, $invoices['rows'][0]['gross']);
        $this->assertSame(0, $invoices['more']);
    }

    public function test_the_invoice_list_stops_at_five_and_counts_the_rest(): void
    {
        foreach (range(1, 7) as $month) {
            $this->invoice("1/0{$month}/2026", 'paid', sprintf('2026-%02d-05', $month), null);
        }

        $invoices = $this->billing()['invoices'];

        $this->assertCount(5, $invoices['rows']);
        $this->assertSame(2, $invoices['more']);
        $this->assertSame('1/07/2026', $invoices['rows'][0]['number']);
    }

    public function test_without_an_invoice_link_template_no_infakt_link_is_offered(): void
    {
        config(['services.infakt.invoice_url' => '']);
        $this->invoice('1/09/2026', 'paid', '2026-09-05', null);

        $this->assertNull($this->billing()['invoices']['rows'][0]['external_url']);
    }

    public function test_another_accounts_reports_invoices_and_retainers_never_appear(): void
    {
        $other = Account::create(['name' => 'Other']);
        $foreign = $other->clients()->create(['name' => 'Obcy', 'currency' => 'PLN']);
        $foreignReport = ClientReport::create([
            'account_id' => $other->id,
            'client_id' => $foreign->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_DRAFT,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'actual_hours' => 1,
            'composer_key' => 'manual',
        ]);
        ClientRetainer::create(['account_id' => $other->id, 'client_id' => $foreign->id, 'monthly_hours' => 9, 'effective_from' => '2026-01-01']);
        Invoice::create(['account_id' => $other->id, 'client_id' => $foreign->id, 'external_id' => 'x-1', 'number' => '9/09/2026', 'status' => 'paid', 'invoice_date' => '2026-09-05']);

        $billing = $this->billing();
        $this->assertSame([], $billing['ledger']['rows']);
        $this->assertSame([$this->dormant->id], array_column($billing['retainer']['positions'], 'id'));
        $this->assertSame([], $billing['invoices']['rows']);

        $this->actingAs($this->user)->get("/clients/{$foreign->id}/rozliczenia")->assertNotFound();
        $this->actingAs($this->user)
            ->patch("/clients/{$this->client->id}/reports/{$foreignReport->id}/opening-balance", ['opening_balance_hours' => 5])
            ->assertNotFound();
        $this->actingAs($this->user)
            ->patch("/clients/{$foreign->id}/reports/{$foreignReport->id}/opening-balance", ['opening_balance_hours' => 5])
            ->assertNotFound();
        $this->assertNull($foreignReport->fresh()->opening_balance_hours);
    }

    public function test_rozliczenia_and_dane_send_the_same_props_and_queries_whatever_the_phone_asks_for(): void
    {
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
        $this->report('2026-09', ClientReport::STATUS_DRAFT, contracted: 10, actual: 4);
        $this->invoice('1/09/2026', 'paid', '2026-09-30', '2026-09-30', paid: '2026-10-02');
        $this->client->contacts()->create(['account_id' => $this->account->id, 'first_name' => 'Ola', 'last_name' => 'Nowak']);
        $iphone = ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'];
        $desktop = ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15'];

        foreach (['rozliczenia', 'dane'] as $tab) {
            $url = "/clients/{$this->client->id}/{$tab}";
            $this->actingAs($this->user)->get($url)->assertOk();

            [$plain, $plainQueries] = $this->measured($url, $desktop);
            [$phone, $phoneQueries] = $this->measured($url, $iphone);

            $this->assertSame($plain, $phone, $tab);
            $this->assertSame($plainQueries, $phoneQueries, $tab);
        }
    }

    /** @return array<string, mixed> */
    private function billing(): array
    {
        $response = $this->actingAs($this->user)->get("/clients/{$this->client->id}/rozliczenia");
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        return [...$props['billing'], 'ledger' => $props['billing_ledger']];
    }

    private function retainer(float $hours, ?float $cap = null, ?float $fee = null, ?float $overage = null): ClientRetainer
    {
        return ClientRetainer::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'monthly_hours' => $hours,
            'monthly_fee' => $fee,
            'overage_hourly_rate' => $overage,
            'rollover_cap_hours' => $cap,
            'currency' => 'PLN',
            'effective_from' => '2026-01-01',
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{array<string, mixed>, int}
     */
    private function measured(string $url, array $headers): array
    {
        // withHeaders() persists across requests, so each measurement starts from none.
        $this->flushHeaders();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $props = $this->actingAs($this->user)->withHeaders($headers)->get($url)->assertOk()->viewData('page')['props'];
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        unset($props['errors'], $props['auth']);

        return [$props, $queries];
    }

    private function report(string $month, string $status, float $opening = 0, float $contracted = 10, float $actual = 0, ?float $cap = null, ?string $sentAt = null): ClientReport
    {
        $start = Carbon::parse($month.'-01');

        return ClientReport::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'period_type' => ClientReport::PERIOD_MONTH,
            'status' => $status,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'contracted_hours' => $contracted,
            'actual_hours' => $actual,
            'opening_balance_hours' => $opening,
            'rollover_cap_hours' => $cap,
            'composer_key' => 'structured_list',
            'body_markdown' => "## Podsumowanie rozliczeniowe\n\n- Bilans otwarcia: 0 h",
            'sent_at' => $sentAt !== null ? Carbon::parse($sentAt, 'UTC') : null,
        ]);
    }

    private function invoice(string $number, string $status, string $issued, ?string $sale, ?string $payment = null, ?string $paid = null): Invoice
    {
        return Invoice::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'external_id' => 'ext-'.$number,
            'number' => $number,
            'status' => $status,
            'currency' => 'PLN',
            'net_price' => 180000,
            'gross_price' => 221400,
            'invoice_date' => $issued,
            'sale_date' => $sale,
            'payment_date' => $payment,
            'paid_date' => $paid,
        ]);
    }

    private function entry(Task $task, string $startUtc, int $minutes): TimeEntry
    {
        $start = Carbon::parse($startUtc, 'UTC');

        return TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);
    }
}

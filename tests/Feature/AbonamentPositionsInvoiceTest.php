<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Services\Integrations\InfaktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AbonamentPositionsInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Studio']);
    }

    public function test_fee_positions_in_one_group_render_as_one_multi_line_invoice_and_zero_fee_is_excluded(): void
    {
        // A multi-site client's shape: 3 site fees + a subscription on one invoice, plus a
        // 0-fee dev-hours allowance that must NOT appear as a line.
        $client = $this->maintenanceClient();
        $this->position($client, 'shop-one.test', 300, 1, 0, "Administrowanie stroną shop-one.test\nAnna Przykładowa");
        $this->position($client, 'shop-two.test', 300, 1, 1, "Administrowanie stroną shop-two.test\nAnna Przykładowa");
        $this->position($client, 'cookie-widget', 25, 1, 2, 'Subskrypcja cookie-widget.test - cookies popup');
        $this->position($client, 'dev-hours', null, 1, 3, null, 2.0, 10.0);

        $payloads = $this->service()->buildMaintenanceInvoicePayloads($client, Carbon::parse('2026-06-15'));

        $this->assertCount(1, $payloads);
        $services = $payloads[0]['invoice']['services'];
        $this->assertCount(3, $services); // dev-hours (0 fee) excluded
        $this->assertSame("Administrowanie stroną shop-one.test\nAnna Przykładowa", $services[0]['name']);
        $this->assertSame(30000, $services[0]['unit_net_price']);
        $this->assertSame('2026-06-30', $payloads[0]['invoice']['sale_date']);
    }

    public function test_different_groups_render_as_separate_invoices(): void
    {
        // Two sites billed separately, each on its own invoice.
        $client = $this->maintenanceClient();
        $this->position($client, 'venue-one.test', 120, 1, 0, 'Administracja stroną www.venue-one.test');
        $this->position($client, 'venue-two.test', 120, 2, 0, 'Administracja stroną www.venue-two.test');

        $payloads = $this->service()->buildMaintenanceInvoicePayloads($client, Carbon::parse('2026-06-15'));

        $this->assertCount(2, $payloads);
        $this->assertCount(1, $payloads[0]['invoice']['services']);
        $this->assertCount(1, $payloads[1]['invoice']['services']);
    }

    public function test_inactive_positions_are_excluded(): void
    {
        $client = $this->maintenanceClient();
        $this->position($client, 'active', 100, 1, 0, 'Active line');
        $retired = $this->position($client, 'retired', 210, 1, 1, 'Retired line');
        $retired->update(['is_active' => false]);

        $payloads = $this->service()->buildMaintenanceInvoicePayloads($client, Carbon::parse('2026-06-15'));

        $this->assertCount(1, $payloads);
        $this->assertCount(1, $payloads[0]['invoice']['services']);
        $this->assertSame('Active line', $payloads[0]['invoice']['services'][0]['name']);
    }

    public function test_line_name_falls_back_to_label_then_client_description(): void
    {
        $service = $this->service();

        $client = $this->maintenanceClient('Utrzymanie strony i wsparcie techniczne');
        // No per-position description → falls back to label.
        $this->position($client, 'my-label', 200, 1, 0, null);

        $payloads = $service->buildMaintenanceInvoicePayloads($client, Carbon::parse('2026-06-15'));
        $this->assertSame('my-label', $payloads[0]['invoice']['services'][0]['name']);

        // No label either → falls back to the client's maintenance description.
        $client2 = $this->maintenanceClient('Utrzymanie strony i wsparcie techniczne');
        $client2->retainers()->create([
            'account_id' => $this->account->id,
            'label' => null,
            'monthly_hours' => 0,
            'monthly_fee' => 210,
            'invoice_group' => 1,
            'currency' => 'PLN',
            'effective_from' => '2025-01-01',
        ]);
        $payloads2 = $service->buildMaintenanceInvoicePayloads($client2, Carbon::parse('2026-06-15'));
        $this->assertSame('Utrzymanie strony i wsparcie techniczne', $payloads2[0]['invoice']['services'][0]['name']);
    }

    private function position(
        Client $client,
        string $label,
        ?float $fee,
        int $group,
        int $sort,
        ?string $description = null,
        float $hours = 0,
        ?float $rollover = null,
    ): \App\Models\ClientRetainer {
        return $client->retainers()->create([
            'account_id' => $this->account->id,
            'label' => $label,
            'description' => $description,
            'monthly_hours' => $hours,
            'monthly_fee' => $fee,
            'rollover_cap_hours' => $rollover,
            'invoice_group' => $group,
            'vat_symbol' => '23',
            'currency' => 'PLN',
            'is_active' => true,
            'sort_order' => $sort,
            'effective_from' => '2025-01-01',
        ]);
    }

    private function service(): InfaktService
    {
        return new InfaktService(Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'infakt',
            'api_key' => 'test-key',
            'is_enabled' => true,
        ]));
    }

    private function maintenanceClient(?string $description = null): Client
    {
        return $this->account->clients()->create([
            'name' => 'Multi Site Sp. z o.o.',
            'month_close_type' => 'maintenance',
            'external_ids' => ['infakt' => '10000003'],
            'maintenance_invoice_description' => $description,
        ]);
    }
}

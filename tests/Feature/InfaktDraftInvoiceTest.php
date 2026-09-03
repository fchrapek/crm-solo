<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Services\Integrations\InfaktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class InfaktDraftInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Studio']);
    }

    public function test_builds_a_draft_maintenance_payload_with_month_end_sale_date(): void
    {
        $payload = $this->service()->buildMaintenanceInvoicePayload(
            $this->maintenanceClient('Utrzymanie strony i wsparcie techniczne'),
            Carbon::parse('2026-06-15'),
        )['invoice'];

        $this->assertSame(10000002, $payload['client_id']);
        $this->assertSame('2026-06-30', $payload['sale_date']); // last day of the billed month
        $this->assertSame('transfer', $payload['payment_method']);

        $line = $payload['services'][0];
        $this->assertSame('Utrzymanie strony i wsparcie techniczne', $line['name']);
        $this->assertSame(22000, $line['unit_net_price']); // 220,00 in grosze
        $this->assertSame('23', $line['tax_symbol']);
        $this->assertSame(1, $line['quantity']);
    }

    public function test_falls_back_to_default_description(): void
    {
        $payload = $this->service()->buildMaintenanceInvoicePayload(
            $this->maintenanceClient(null),
            Carbon::parse('2026-06-15'),
        )['invoice'];

        $this->assertSame(InfaktService::DEFAULT_MAINTENANCE_DESCRIPTION, $payload['services'][0]['name']);
    }

    public function test_refuses_a_non_maintenance_client(): void
    {
        $client = $this->account->clients()->create([
            'name' => 'Gig Co',
            'month_close_type' => 'gig',
            'external_ids' => ['infakt' => '1'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->buildMaintenanceInvoicePayload($client, Carbon::parse('2026-06-15'));
    }

    public function test_refuses_a_client_without_an_active_retainer(): void
    {
        $client = $this->account->clients()->create([
            'name' => 'No Retainer',
            'month_close_type' => 'maintenance',
            'external_ids' => ['infakt' => '1'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->buildMaintenanceInvoicePayload($client, Carbon::parse('2026-06-15'));
    }

    public function test_refuses_a_client_without_a_linked_infakt_id(): void
    {
        $client = $this->maintenanceClient('x');
        $client->update(['external_ids' => []]);

        $this->expectException(RuntimeException::class);
        $this->service()->buildMaintenanceInvoicePayload($client->fresh(), Carbon::parse('2026-06-15'));
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

    private function maintenanceClient(?string $description, float $fee = 220): Client
    {
        $client = $this->account->clients()->create([
            'name' => 'Roofs Ltd',
            'month_close_type' => 'maintenance',
            'external_ids' => ['infakt' => '10000002'],
            'maintenance_invoice_description' => $description,
        ]);
        $client->retainers()->create([
            'account_id' => $this->account->id,
            'monthly_hours' => 0,
            'monthly_fee' => $fee,
            'currency' => 'PLN',
            'effective_from' => '2025-01-01',
            'effective_to' => null,
        ]);

        return $client;
    }
}

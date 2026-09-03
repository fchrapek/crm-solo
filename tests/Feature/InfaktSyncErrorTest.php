<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\InfaktApiException;
use App\Services\Integrations\InfaktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class InfaktSyncErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_persists_last_sync_error_and_does_not_bump_last_synced_at_on_401(): void
    {
        Http::fake([
            'api.infakt.pl/v3/clients.json*' => Http::response(
                ['error' => 'Klucz API który podałeś jest nieprawidłowy.'],
                401,
            ),
        ]);

        $account = Account::create(['name' => 'Acme']);
        $integration = Integration::create([
            'account_id' => $account->id,
            'provider' => 'infakt',
            'api_key' => 'bad-key',
            'is_enabled' => true,
            'last_synced_at' => null,
        ]);

        $service = new InfaktService($integration);

        try {
            $service->syncClients($account->id);
            $this->fail('Expected InfaktApiException');
        } catch (InfaktApiException $e) {
            $this->assertSame(401, $e->status);
        }

        $integration->refresh();
        $this->assertNotNull($integration->last_sync_error);
        $this->assertStringContainsString('401', $integration->last_sync_error);
        $this->assertNull($integration->last_synced_at);
    }

    public function test_sync_endpoint_returns_error_flash_on_401(): void
    {
        Http::fake([
            'api.infakt.pl/v3/clients.json*' => Http::response(
                ['error' => 'Klucz API który podałeś jest nieprawidłowy.'],
                401,
            ),
        ]);

        $account = Account::create(['name' => 'Acme']);
        $user = User::factory()->for($account)->create();
        Integration::create([
            'account_id' => $account->id,
            'provider' => 'infakt',
            'api_key' => 'bad-key',
            'is_enabled' => true,
        ]);

        $this->actingAs($user)
            ->from('/integrations/infakt')
            ->post('/integrations/infakt/sync')
            ->assertRedirect('/integrations/infakt')
            ->assertSessionHas('error')
            ->assertSessionMissing('success');
    }

    public function test_sync_clears_last_sync_error_and_bumps_timestamp_on_success(): void
    {
        Http::fake([
            'api.infakt.pl/v3/clients.json*' => Http::response([
                'entities' => [],
                'metainfo' => ['total_count' => 0],
            ], 200),
        ]);

        $account = Account::create(['name' => 'Acme']);
        $integration = Integration::create([
            'account_id' => $account->id,
            'provider' => 'infakt',
            'api_key' => 'good-key',
            'is_enabled' => true,
            'last_sync_error' => 'previous failure',
            'last_synced_at' => null,
        ]);

        $service = new InfaktService($integration);
        $stats = $service->syncClients($account->id);

        $integration->refresh();
        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertNull($integration->last_sync_error);
        $this->assertNotNull($integration->last_synced_at);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Sync button reports the Infakt clients it could not link, and the
 * integration page keeps listing them until a sync finds none.
 */
final class InfaktWebSyncConflictsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $infaktSide = [];

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        Http::fake([
            'api.infakt.pl/*' => fn () => Http::response([
                'entities' => $this->infaktSide,
                'metainfo' => ['total_count' => count($this->infaktSide)],
            ]),
        ]);

        $this->account = Account::create(['name' => 'Studio']);
        $this->user = User::factory()->for($this->account)->create();
        Integration::create(['account_id' => $this->account->id, 'provider' => 'infakt', 'api_key' => 'key', 'is_enabled' => true]);
    }

    public function test_conflicts_are_flashed_and_kept_on_the_page_until_a_clean_sync(): void
    {
        $linked = Client::create([
            'account_id' => $this->account->id, 'name' => 'Acme', 'tax_id' => '5260000000', 'external_ids' => ['infakt' => '202'],
        ]);
        $this->infaktSide = [$this->infaktClient(303, '526-000-00-00')];

        $this->actingAs($this->user)->from('/integrations/infakt')->post('/integrations/infakt/sync')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Sync completed. Created: 0, Updated: 0')
                && str_contains($message, '1 Infakt client was not linked')
                && str_contains($message, "Infakt client 303 (NIP 5260000000) matches CRM client #{$linked->id}"))
            ->assertSessionMissing('success');

        $this->actingAs($this->user)->get('/integrations/infakt')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('integration.client_conflicts', 1)
                ->where('integration.client_conflicts.0', fn (string $c) => str_contains($c, 'Infakt client 303'))
                ->where('integration.last_sync_error', null));

        // Saving the form does not drop the list.
        $this->actingAs($this->user)->put('/integrations/infakt', ['is_enabled' => true, 'settings' => ['client_conflicts' => []]]);
        $this->assertCount(1, Integration::sole()->settings['client_conflicts']);

        // The conflicting record is gone from Infakt: the next sync clears the list.
        $this->infaktSide = [];
        $this->actingAs($this->user)->from('/integrations/infakt')->post('/integrations/infakt/sync')
            ->assertSessionHas('success')
            ->assertSessionMissing('error');
        $this->assertSame([], Integration::sole()->settings['client_conflicts']);
    }

    public function test_many_conflicts_name_three_and_count_the_rest_in_polish(): void
    {
        foreach (range(1, 5) as $n) {
            Client::create([
                'account_id' => $this->account->id, 'name' => "Firma {$n}", 'tax_id' => "526000000{$n}", 'external_ids' => ['infakt' => (string) (200 + $n)],
            ]);
            $this->infaktSide[] = $this->infaktClient(300 + $n, "526000000{$n}");
        }
        $this->actingAs($this->user)->withHeader('Accept-Language', 'pl')->from('/integrations/infakt')->post('/integrations/infakt/sync')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '5 klientów Infakt nie zostało powiązanych')
                && mb_substr_count($message, 'matches CRM client') === 3
                && str_contains($message, '(i jeszcze 2, lista na stronie integracji)'));

        $this->assertCount(5, Integration::sole()->settings['client_conflicts']);
    }

    /**
     * @return array<string, mixed>
     */
    private function infaktClient(int $id, string $nip): array
    {
        return [
            'id' => $id, 'company_name' => "Infakt {$id}", 'email' => null, 'phone_number' => null, 'street' => 'Prosta 1',
            'city' => 'Warszawa', 'postal_code' => '00-001', 'country' => 'PL', 'nip' => $nip, 'note' => null,
        ];
    }
}

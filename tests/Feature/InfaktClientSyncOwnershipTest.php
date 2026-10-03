<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Services\Integrations\InfaktService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Infakt seeds a client on first import; afterwards it refreshes only the
 * fields the CRM has not edited, and never brings back a deleted client.
 */
final class InfaktClientSyncOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Integration $integration;

    /** @var array<string, mixed> */
    private array $infaktSide = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            'api.infakt.pl/*' => fn () => Http::response([
                'entities' => [$this->infaktSide],
                'metainfo' => ['total_count' => 1],
            ]),
        ]);

        $this->account = Account::create(['name' => 'Studio']);
        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'infakt',
            'api_key' => 'test-key',
            'is_enabled' => true,
        ]);
    }

    public function test_first_import_seeds_the_client_with_a_general_project(): void
    {
        $this->fakeInfakt($this->infaktClient());

        $stats = $this->sync();

        $this->assertSame(1, $stats['created']);
        $client = Client::sole();
        $this->assertSame('Acme Sp. z o.o.', $client->name);
        $this->assertSame('Warszawa', $client->city);
        $this->assertSame('5260000000', $client->tax_id);
        $this->assertSame('500', $client->external_ids['infakt']);
        $this->assertSame(['General'], $client->projects()->pluck('name')->all());
    }

    public function test_an_infakt_change_reaches_a_field_the_crm_left_alone(): void
    {
        $this->fakeInfakt($this->infaktClient());
        $this->sync();

        $this->fakeInfakt($this->infaktClient(['city' => 'Krakow', 'phone_number' => '700800900']));
        $this->sync();

        $client = Client::sole();
        $this->assertSame('Krakow', $client->city);
        $this->assertSame('700800900', $client->phone);
    }

    public function test_a_field_edited_in_the_crm_survives_every_later_sync(): void
    {
        $this->fakeInfakt($this->infaktClient());
        $this->sync();

        $client = Client::sole();
        $client->update([
            'name' => 'Acme',
            'notes' => 'Pays late, call first.',
            'type' => 'individual',
            'external_ids' => [...$client->external_ids, 'other' => 'kept'],
        ]);

        $this->fakeInfakt($this->infaktClient(['company_name' => 'ACME SPOLKA', 'note' => 'Infakt note', 'city' => 'Gdansk']));
        $this->sync();
        $this->sync();

        $client->refresh();
        $this->assertSame('Acme', $client->name);
        $this->assertSame('Pays late, call first.', $client->notes);
        $this->assertSame('individual', $client->type, 'the legal type is the CRM\'s after import');
        $this->assertSame('Gdansk', $client->city, 'an untouched field still follows Infakt');
        $this->assertSame('kept', $client->external_ids['other']);
        $this->assertSame('500', $client->external_ids['infakt']);
    }

    public function test_an_owner_edit_that_infakt_later_matches_stays_the_owners(): void
    {
        $this->fakeInfakt($this->infaktClient(['city' => 'Alpha']));
        $this->sync();

        Client::sole()->update(['city' => 'Bravo']);

        $this->fakeInfakt($this->infaktClient(['city' => 'Bravo']));
        $this->sync();
        $this->assertSame('Bravo', Client::sole()->city);

        $this->fakeInfakt($this->infaktClient(['city' => 'Charlie']));
        $this->sync();

        $this->assertSame('Bravo', Client::sole()->city, 'Infakt never wrote Bravo, so it is still the owner\'s value');
    }

    public function test_a_legacy_client_follows_infakt_on_fields_that_already_matched(): void
    {
        Client::create([
            'account_id' => $this->account->id,
            'name' => 'Acme renamed in CRM',
            'city' => 'Warszawa',
            'tax_id' => '5260000000',
            'external_ids' => ['infakt' => '500'],
        ]);

        $this->fakeInfakt($this->infaktClient());
        $this->sync();
        $this->fakeInfakt($this->infaktClient(['company_name' => 'Acme New Name', 'city' => 'Lodz']));
        $this->sync();

        $client = Client::sole();
        $this->assertSame('Lodz', $client->city, 'the city matched Infakt, so Infakt keeps it');
        $this->assertSame('Acme renamed in CRM', $client->name, 'the name never matched, so it stays the owner\'s');
    }

    public function test_a_note_the_crm_rewrites_on_save_is_not_taken_for_an_edit(): void
    {
        $this->fakeInfakt($this->infaktClient(['note' => "Billing \u{2014} quarterly"]));
        $this->sync();

        $this->fakeInfakt($this->infaktClient(['note' => 'Billing monthly']));
        $this->sync();

        $this->assertSame('Billing monthly', Client::sole()->notes);
    }

    public function test_a_client_imported_before_the_snapshot_keeps_its_fields_once_then_follows_infakt(): void
    {
        $legacy = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Acme renamed in CRM',
            'city' => 'Warszawa',
            'tax_id' => '5260000000',
            'external_ids' => ['infakt' => '500'],
        ]);

        $this->fakeInfakt($this->infaktClient(['city' => 'Poznan']));
        $this->sync();

        $legacy->refresh();
        $this->assertSame('Acme renamed in CRM', $legacy->name);
        $this->assertSame('Warszawa', $legacy->city, 'without a snapshot nothing tells an edit apart, so the CRM value stays');

        $this->fakeInfakt($this->infaktClient(['city' => 'Lodz']));
        $this->sync();

        $this->assertSame('Warszawa', $legacy->fresh()->city, 'still differs from what Infakt last wrote');
        $this->assertSame('Acme renamed in CRM', $legacy->fresh()->name);
    }

    public function test_a_client_deleted_in_the_crm_stays_deleted_and_is_not_duplicated(): void
    {
        $this->fakeInfakt($this->infaktClient());
        $this->sync();
        Client::sole()->delete();

        $stats = $this->sync();

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, Client::count());
        $this->assertSame(1, Client::withTrashed()->count());
    }

    public function test_a_deleted_client_matched_by_nip_is_not_duplicated(): void
    {
        $deleted = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Old Acme',
            'tax_id' => '5260000000',
        ]);
        $deleted->delete();

        $this->fakeInfakt($this->infaktClient());
        $this->sync();

        $this->assertSame(0, Client::count());
        $this->assertSame(1, Client::withTrashed()->count());
    }

    public function test_a_live_client_is_preferred_over_a_deleted_one_with_the_same_nip(): void
    {
        $deleted = Client::create(['account_id' => $this->account->id, 'name' => 'Old Acme', 'tax_id' => '5260000000']);
        $deleted->delete();
        $live = Client::create(['account_id' => $this->account->id, 'name' => 'Acme', 'tax_id' => '5260000000']);

        $this->fakeInfakt($this->infaktClient());
        $stats = $this->sync();

        $this->assertSame(1, $stats['updated']);
        $this->assertSame('500', $live->fresh()->external_ids['infakt']);
        $this->assertNull($deleted->fresh()->external_ids);
    }

    public function test_a_deleted_exact_infakt_match_wins_over_a_live_nip_match(): void
    {
        $deleted = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Old Acme',
            'tax_id' => '5260000000',
            'external_ids' => ['infakt' => '101'],
        ]);
        $deleted->delete();
        $live = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Acme',
            'tax_id' => '5260000000',
            'external_ids' => ['infakt' => '202'],
        ]);

        $this->fakeInfakt($this->infaktClient(['id' => 101]));
        $stats = $this->sync();

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame('202', $live->fresh()->external_ids['infakt']);
        $this->assertSame('Acme', $live->fresh()->name);
        $this->assertSame(2, Client::withTrashed()->count());
    }

    public function test_a_nip_match_linked_to_another_infakt_client_is_a_reported_conflict(): void
    {
        $linked = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Acme',
            'tax_id' => '5260000000',
            'external_ids' => ['infakt' => '202'],
        ]);

        $this->fakeInfakt($this->infaktClient(['id' => 303]));
        $stats = $this->sync();

        $this->assertCount(1, $stats['conflicts']);
        $this->assertStringContainsString("#{$linked->id}", $stats['conflicts'][0]);
        $this->assertSame('202', $linked->fresh()->external_ids['infakt'], 'not relinked');
        $this->assertSame(1, Client::count(), 'no duplicate created');

        $this->artisan('infakt:sync-clients --force')
            ->expectsOutputToContain('already linked to Infakt client 202')
            ->assertSuccessful();
    }

    public function test_a_nip_match_without_an_infakt_id_is_linked(): void
    {
        $unlinked = Client::create(['account_id' => $this->account->id, 'name' => 'Acme', 'tax_id' => '5260000000']);

        $this->fakeInfakt($this->infaktClient());
        $stats = $this->sync();

        $this->assertSame(1, $stats['updated']);
        $this->assertSame('500', $unlinked->fresh()->external_ids['infakt']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(): array
    {
        // Built per call: the HTTP client binds to the fake that exists when it is made.
        return (new InfaktService($this->integration))->syncClients($this->account->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function infaktClient(array $overrides = []): array
    {
        return [
            'id' => 500,
            'company_name' => 'Acme Sp. z o.o.',
            'email' => 'biuro@acme.test',
            'phone_number' => '600100200',
            'street' => 'Prosta 1',
            'city' => 'Warszawa',
            'postal_code' => '00-001',
            'country' => 'PL',
            'nip' => '526-000-00-00',
            'note' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function fakeInfakt(array $client): void
    {
        $this->infaktSide = $client;
    }
}

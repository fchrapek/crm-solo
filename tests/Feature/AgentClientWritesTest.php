<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\ClientCreateTool;
use App\Mcp\Tools\ClientsTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\Contact;
use App\Models\User;
use App\Services\Clients\ClientNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\TestCase;

/**
 * Agents list clients and add one with the basics. A name close to an
 * existing client is refused with the matches until the caller forces it.
 */
final class AgentClientWritesTest extends TestCase
{
    use CallsAgentSurfaces;
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        config(['agent.local_account' => $this->account->id]);
        User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function names(): array
    {
        return [
            'case and spacing' => ['Pracownia Wzorcowa', 'pracowniawzorcowa', true],
            'diacritics' => ['Łódzka Piekarnia Testowa', 'Lodzka piekarnia testowa', true],
            'legal form' => ['Hurtownia Testowa sp. z o.o.', 'HURTOWNIA TESTOWA', true],
            'joint stock' => ['Budowa Przykładowa S.A.', 'Budowa Przykładowa', true],
            'limited partnership' => ['Kancelaria Testowa sp.k.', 'kancelaria testowa', true],
            'trade prefix' => ['PHU Kowalski', 'Kowalski', true],
            'dotted trade prefix' => ['P.H.U. Kowalski', 'Kowalski', true],
            'dotted prefix that starts like a shorter one' => ['P.P.H.U. Kowalski', 'Kowalski', true],
            'two legal forms' => ['Agencja Testowa Sp. z o.o. Sp.k.', 'Agencja Testowa', true],
            'and Co' => ['Smith & Co', 'Smith', true],
            'a bare Sp.' => ['Hurtownia Testowa Sp.', 'Hurtownia Testowa', true],
            'a bare spółka' => ['Hurtownia Testowa Spółka', 'hurtownia testowa', true],
            'z o.o. without sp' => ['Hurtownia Testowa z o.o.', 'Hurtownia Testowa', true],
            'spaced legal form' => ['Przychodnia Testowa s. c.', 'Przychodnia Testowa', true],
            'English form' => ['Acme Ltd', 'ACME', true],
            'one typo on a long name' => ['Warsztat Samochodowy Testowy', 'Warsztat Samochodowy Testoy', true],
            'one letter apart on a short name' => ['Testa', 'Testo', false],
            'different company' => ['Pracownia Wzorcowa', 'Pracownia Testowa', false],
            'legal form only' => ['S.A.', 'Kowalski', false],
        ];
    }

    public function test_client_create_writes_the_basics_a_contact_and_a_general_project(): void
    {
        [$exit, $out] = $this->verb('crm:client-create', [
            '--name' => 'Kowalski Sklep', '--type' => 'one-off', '--contact' => 'Jan Kowalski',
            '--email' => 'jan@example.com', '--phone' => '+48 100 100 200', '--note' => "Call on Thursday \u{2014} they sell online.",
        ]);

        $this->assertSame(0, $exit);
        $client = Client::query()->findOrFail($out['client']['id']);
        $this->assertSame(['Kowalski Sklep', 'business', 'one_off', 'jan@example.com', '+48 100 100 200', 'active'], [$client->name, $client->type, $client->cooperation_type, $client->email, $client->phone, $client->lifecycle_stage]);
        $this->assertStringNotContainsString("\u{2014}", (string) $client->notes);
        $this->assertNotNull($client->generalProject());
        $this->assertSame(1, $client->lifecycleEvents()->count());
        $contact = Contact::query()->findOrFail($out['contact_id']);
        $this->assertSame(['Jan', 'Kowalski', ['jan@example.com'], $client->id, $this->account->id], [$contact->first_name, $contact->last_name, $contact->emails, $contact->client_id, $contact->account_id]);
        $this->assertSame(['type' => 'one_off', 'stage' => 'active', 'billing_mode' => 'order'], array_intersect_key($out['client'], array_flip(['type', 'stage', 'billing_mode'])));
    }

    public function test_client_create_checks_email_nip_and_type_with_the_forms_rules(): void
    {
        [$exit, $out] = $this->verb('crm:client-create', ['--name' => 'Acme', '--email' => 'not-an-email']);
        $this->assertSame([1, 'invalid_argument'], [$exit, $out['error']]);
        $this->assertArrayHasKey('email', $out['errors']);

        [, $nip] = $this->tool(ClientCreateTool::class, ['name' => 'Acme', 'nip' => '123-456-78-91']);
        $this->assertArrayHasKey('nip', $nip['errors']);

        [, $type] = $this->tool(ClientCreateTool::class, ['name' => 'Acme', 'type' => 'monthly']);
        $this->assertSame(['retainer', 'one_off'], $type['valid_types']);

        $this->assertSame(0, Client::query()->count());
    }

    public function test_a_valid_nip_is_stored_without_its_dashes(): void
    {
        // Check remainder 10 is never issued as a NIP, yet the NIP rule reads it as 0 and accepts the number.
        [, $out] = $this->tool(ClientCreateTool::class, ['name' => 'Acme', 'nip' => '123-456-78-90']);

        $this->assertSame('1234567890', Client::query()->findOrFail($out['client']['id'])->tax_id);
    }

    public function test_a_close_name_is_refused_with_the_matches_until_forced(): void
    {
        $existing = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Pracownia Wzorcowa Sp. z o.o.']);
        $archived = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'PRACOWNIAWZORCOWA']);
        $archived->delete();

        [$exit, $out] = $this->verb('crm:client-create', ['--name' => 'pracownia wzorcowa']);

        $this->assertSame([1, 'possible_duplicate'], [$exit, $out['error']]);
        $this->assertSame([$existing->id, $archived->id], collect($out['candidates'])->pluck('id')->sort()->values()->all());
        $this->assertSame('archived', collect($out['candidates'])->firstWhere('id', $archived->id)['context']);
        $this->assertSame(1, Client::query()->count());

        [$ok, $forced] = $this->tool(ClientCreateTool::class, ['name' => 'pracownia wzorcowa', 'force' => true]);
        $this->assertTrue($ok);
        $this->assertTrue($forced['forced']);
        $this->assertSame(2, Client::query()->count());
    }

    public function test_the_duplicate_guard_looks_only_inside_the_acting_account(): void
    {
        $stranger = Account::factory()->create();
        Client::factory()->create(['account_id' => $stranger->id, 'name' => 'Testa']);

        [$ok, $out] = $this->tool(ClientCreateTool::class, ['name' => 'Testa']);

        $this->assertTrue($ok);
        $this->assertSame($this->account->id, Client::query()->findOrFail($out['client']['id'])->account_id);
    }

    #[DataProvider('names')]
    public function test_names_match_on_a_folded_key(string $existing, string $new, bool $close): void
    {
        Client::factory()->create(['account_id' => $this->account->id, 'name' => $existing]);

        $this->assertSame($close, app(ClientNameMatcher::class)->similar($this->account->id, $new)->isNotEmpty());
    }

    public function test_clients_lists_type_stage_billing_mode_and_todays_retainer(): void
    {
        $retainer = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Alpha', 'cooperation_type' => 'retainer']);
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $retainer->id, 'monthly_hours' => 10, 'monthly_fee' => 2000, 'currency' => 'PLN', 'effective_from' => '2026-01-01', 'is_active' => true]);
        Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Beta', 'cooperation_type' => 'one_off', 'lifecycle_stage' => 'paused']);
        Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Gone'])->delete();

        [$exit, $out] = $this->verb('crm:clients');

        $this->assertSame(0, $exit);
        $this->assertSame(['Alpha', 'Beta'], collect($out['clients'])->pluck('name')->all());
        $alpha = $out['clients'][0];
        $this->assertSame(['retainer', 'retainer'], [$alpha['type'], $alpha['billing_mode']]);
        $this->assertEquals(['positions' => 1, 'monthly_hours' => 10.0, 'monthly_fee' => 2000.0, 'currency' => 'PLN'], $alpha['retainer']);
        $this->assertNull($out['clients'][1]['retainer']);

        [, $paused] = $this->tool(ClientsTool::class, ['stage' => 'paused']);
        $this->assertSame(['Beta'], collect($paused['clients'])->pluck('name')->all());
        [, $archived] = $this->tool(ClientsTool::class, ['archived' => true, 'search' => 'gon']);
        $this->assertTrue($archived['clients'][0]['archived']);
        [, $bad] = $this->tool(ClientsTool::class, ['type' => 'monthly']);
        $this->assertSame('invalid_argument', $bad['error']);
    }

    public function test_clients_never_lists_another_accounts_clients(): void
    {
        $stranger = Account::factory()->create();
        Client::factory()->create(['account_id' => $stranger->id, 'name' => 'Secret Co']);

        [, $out] = $this->tool(ClientsTool::class, ['archived' => true]);

        $this->assertNotContains('Secret Co', collect($out['clients'])->pluck('name')->all());
    }

    public function test_text_output_fences_client_names_and_duplicate_matches(): void
    {
        Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Testa']);

        Artisan::call('crm:client-create', ['--name' => 'TESTA']);
        $output = Artisan::output();

        $this->assertStringStartsWith('ERROR ', $output);
        $this->assertMatchesRegularExpression('/BEGIN EXTERNAL CONTENT [0-9a-f]{8}.*Testa.*END EXTERNAL CONTENT/s', $output);
    }

    public function test_the_client_form_creates_through_the_same_creator(): void
    {
        $owner = User::query()->firstOrFail();

        $this->actingAs($owner)->post('/clients', ['type' => 'business', 'name' => 'Form Co'])->assertRedirect();

        $client = Client::query()->where('name', 'Form Co')->sole();
        $this->assertSame($this->account->id, $client->account_id);
        $this->assertNotNull($client->generalProject());
    }
}

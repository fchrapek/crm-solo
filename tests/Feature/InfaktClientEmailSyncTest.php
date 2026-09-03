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

final class InfaktClientEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Studio']);
    }

    public function test_multiple_emails_become_primary_plus_contacts(): void
    {
        $this->fakeClient('anna.kowalska@multiemail.test, marek.zielinski@multiemail.test');

        $stats = $this->service()->syncClients($this->account->id);
        $this->assertSame(0, $stats['errors']);

        $client = Client::where('account_id', $this->account->id)->firstOrFail();
        $this->assertSame('anna.kowalska@multiemail.test', $client->email);
        $this->assertSame('10000001', $client->external_ids['infakt']);

        $contact = $client->contacts()->firstOrFail();
        $this->assertSame(['marek.zielinski@multiemail.test'], $contact->emails);
        $this->assertSame('Marek', $contact->first_name);
        $this->assertSame('Zielinski', $contact->last_name);
    }

    public function test_resync_does_not_duplicate_the_contact(): void
    {
        $this->fakeClient('anna.kowalska@multiemail.test, marek.zielinski@multiemail.test');

        $service = $this->service();
        $service->syncClients($this->account->id);
        $service->syncClients($this->account->id);

        $client = Client::where('account_id', $this->account->id)->firstOrFail();
        $this->assertSame(1, $client->contacts()->count());
    }

    public function test_single_email_creates_no_contact_and_does_not_error(): void
    {
        $this->fakeClient('one@multiemail.test');

        $stats = $this->service()->syncClients($this->account->id);
        $this->assertSame(0, $stats['errors']);

        $client = Client::where('account_id', $this->account->id)->firstOrFail();
        $this->assertSame('one@multiemail.test', $client->email);
        $this->assertSame(0, $client->contacts()->count());
    }

    private function fakeClient(string $email): void
    {
        Http::fake([
            'api.infakt.pl/*' => Http::response([
                'entities' => [[
                    'id' => 10000001,
                    'company_name' => 'Multi Email Sp. z o.o.',
                    'email' => $email,
                    'nip' => '1234567890',
                    'city' => 'Warszawa',
                    'country' => 'PL',
                ]],
                'metainfo' => ['total_count' => 1],
            ], 200),
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
}

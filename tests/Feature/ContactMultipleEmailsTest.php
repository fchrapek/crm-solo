<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Behavioural tests for the multi-email contact model — the JSON
 * address-list column and its primary-email helper.
 */
final class ContactMultipleEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'A']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'U', 'last_name' => 'X',
            'email' => 'u@one.test', 'owner' => true,
        ]);
        $this->client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
    }

    public function test_emails_column_stores_multiple_addresses(): void
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'first_name' => 'Filip', 'last_name' => 'Ch',
            'emails' => ['one@primary.test', 'two@secondary.test'],
        ]);

        $this->assertSame(['one@primary.test', 'two@secondary.test'], $contact->fresh()->emails);
    }

    public function test_primary_email_helper_returns_first_entry(): void
    {
        $c = Contact::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'first_name' => 'A', 'last_name' => 'B',
            'emails' => ['first@one.test', 'second@one.test'],
        ]);
        $this->assertSame('first@one.test', $c->primaryEmail());

        $empty = Contact::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'first_name' => 'C', 'last_name' => 'D',
        ]);
        $this->assertNull($empty->primaryEmail());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'account_id' => Account::create(['name' => 'Acme Corporation'])->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'johndoe@example.com',
            'owner' => true,
        ]);

        $client = $this->user->account->clients()->create(['name' => 'Example Client Inc.']);

        $this->user->account->contacts()->createMany([
            [
                'client_id' => $client->id,
                'first_name' => 'Martin',
                'last_name' => 'Abbott',
                'emails' => ['martin.abbott@example.com'],
                'phone' => '555-111-2222',
                'address' => '330 Glenda Shore',
                'city' => 'Murphyland',
                'region' => 'Tennessee',
                'country' => 'US',
                'postal_code' => '57851',
            ], [
                'client_id' => $client->id,
                'first_name' => 'Lynn',
                'last_name' => 'Kub',
                'emails' => ['lynn.kub@example.com'],
                'phone' => '555-333-4444',
                'address' => '199 Connelly Turnpike',
                'city' => 'Woodstock',
                'region' => 'Colorado',
                'country' => 'US',
                'postal_code' => '11623',
            ],
        ]);
    }

    public function test_can_view_contacts(): void
    {
        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('contacts/index')
                ->has('contacts.data', 2)
                ->has('contacts.data.0', fn (Assert $assert) => $assert
                    ->has('id')
                    ->where('name', 'Martin Abbott')
                    ->where('phone', '555-111-2222')
                    ->where('city', 'Murphyland')
                    ->where('deleted_at', null)
                    ->has('client', fn (Assert $assert) => $assert
                        ->where('name', 'Example Client Inc.')
                        ->etc()
                    )
                )
                ->has('contacts.data.1', fn (Assert $assert) => $assert
                    ->has('id')
                    ->where('name', 'Lynn Kub')
                    ->where('phone', '555-333-4444')
                    ->where('city', 'Woodstock')
                    ->where('deleted_at', null)
                    ->has('client', fn (Assert $assert) => $assert
                        ->where('name', 'Example Client Inc.')
                        ->etc()
                    )
                )
            );
    }

    public function test_can_search_for_contacts(): void
    {
        $this->actingAs($this->user)
            ->get('/contacts?search=Martin')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('contacts/index')
                ->where('filters.search', 'Martin')
                ->has('contacts.data', 1)
                ->has('contacts.data.0', fn (Assert $assert) => $assert
                    ->has('id')
                    ->where('name', 'Martin Abbott')
                    ->where('phone', '555-111-2222')
                    ->where('city', 'Murphyland')
                    ->where('deleted_at', null)
                    ->has('client', fn (Assert $assert) => $assert
                        ->where('name', 'Example Client Inc.')
                        ->etc()
                    )
                )
            );
    }

    public function test_cannot_view_deleted_contacts(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('contacts/index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Lynn Kub')
            );
    }

    public function test_can_create_contact_with_multiple_emails(): void
    {
        $client = $this->user->account->clients()->first();

        $this->actingAs($this->user)
            ->post('/contacts', [
                'first_name' => 'Filip',
                'last_name' => 'Chrapek',
                'client_id' => $client->id,
                'emails' => ['primary@example.test', 'secondary@example.test'],
            ])
            ->assertRedirect();

        $contact = $this->user->account->contacts()->where('first_name', 'Filip')->first();
        $this->assertNotNull($contact);
        $this->assertSame(['primary@example.test', 'secondary@example.test'], $contact->emails);
        $this->assertSame('primary@example.test', $contact->primaryEmail());
    }

    public function test_validator_rejects_duplicate_emails(): void
    {
        $client = $this->user->account->clients()->first();

        $this->actingAs($this->user)
            ->post('/contacts', [
                'first_name' => 'X',
                'last_name' => 'Y',
                'client_id' => $client->id,
                'emails' => ['a@b.test', 'a@b.test'],
            ])
            ->assertSessionHasErrors('emails.1');
    }

    public function test_validator_rejects_invalid_email_format(): void
    {
        $client = $this->user->account->clients()->first();

        $this->actingAs($this->user)
            ->post('/contacts', [
                'first_name' => 'X',
                'last_name' => 'Y',
                'client_id' => $client->id,
                'emails' => ['not-an-email'],
            ])
            ->assertSessionHasErrors('emails.0');
    }

    public function test_can_update_contact_emails(): void
    {
        $contact = $this->user->account->contacts()->firstWhere('first_name', 'Martin');

        $this->actingAs($this->user)
            ->put('/contacts/'.$contact->id, [
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'client_id' => $contact->client_id,
                'emails' => ['martin.work@example.com', 'martin.personal@example.com'],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['martin.work@example.com', 'martin.personal@example.com'],
            $contact->fresh()->emails,
        );
    }

    public function test_can_filter_to_view_deleted_contacts(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $this->actingAs($this->user)
            ->get('/contacts?trashed=with')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('contacts/index')
                ->has('contacts.data', 2)
                ->where('contacts.data.0.name', 'Martin Abbott')
                ->where('contacts.data.1.name', 'Lynn Kub')
            );
    }
}

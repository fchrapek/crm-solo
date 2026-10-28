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
                    ->etc()
                    ->has('client', fn (Assert $assert) => $assert
                        ->where('name', 'Example Client')
                        ->etc()
                    )
                )
                ->has('contacts.data.1', fn (Assert $assert) => $assert
                    ->has('id')
                    ->where('name', 'Lynn Kub')
                    ->where('phone', '555-333-4444')
                    ->where('city', 'Woodstock')
                    ->where('deleted_at', null)
                    ->etc()
                    ->has('client', fn (Assert $assert) => $assert
                        ->where('name', 'Example Client')
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
                    ->etc()
                    ->has('client', fn (Assert $assert) => $assert
                        ->where('name', 'Example Client')
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
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'client_id' => $client->id,
                'emails' => ['primary@example.test', 'secondary@example.test'],
            ])
            ->assertRedirect();

        $contact = $this->user->account->contacts()->where('first_name', 'Jan')->first();
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

    public function test_the_deleted_contacts_filter_is_echoed_so_a_search_keeps_it_and_takes_no_other_value(): void
    {
        $this->user->account->contacts()->firstWhere('first_name', 'Martin')->delete();

        $this->actingAs($this->user)->get('/contacts?trashed=with&search=abbott')
            ->assertInertia(fn (Assert $assert) => $assert->where('filters', ['search' => 'abbott', 'trashed' => 'with'])->has('contacts.data', 1));
        $this->actingAs($this->user)->get('/contacts?trashed=only')
            ->assertInertia(fn (Assert $assert) => $assert->where('filters.trashed', null)->has('contacts.data', 1));
    }

    public function test_search_matches_every_word_against_the_person_the_email_or_the_client(): void
    {
        $other = $this->user->account->clients()->create(['name' => 'Piekarnia Przykładowa']);
        $this->user->account->contacts()->create(['client_id' => $other->id, 'first_name' => 'Anna', 'last_name' => 'Abbott', 'emails' => ['anna@piekarnia.test']]);

        $names = fn (string $search) => collect($this->actingAs($this->user)->get('/contacts?search='.urlencode($search))->viewData('page')['props']['contacts']['data'])->pluck('name')->all();

        $this->assertSame(['Anna Abbott'], $names('piekarnia'));
        $this->assertSame(['Anna Abbott', 'Martin Abbott'], $names('abbott'));
        $this->assertSame(['Martin Abbott'], $names('martin abbott'));
        $this->assertSame(['Lynn Kub'], $names('lynn.kub@'));
        $this->assertSame(['Martin Abbott', 'Lynn Kub'], $names('example client'));
    }

    public function test_the_client_list_for_the_form_holds_only_the_accounts_clients(): void
    {
        Account::create(['name' => 'Other'])->clients()->create(['name' => 'Foreign Ltd']);

        $this->actingAs($this->user)->get('/contacts')
            ->assertInertia(fn (Assert $assert) => $assert->where('clients', [['id' => $this->user->account->clients()->first()->id, 'name' => 'Example Client']])->where('opened', null));
    }

    public function test_kontakt_opens_the_form_for_a_new_or_an_existing_contact_of_the_account(): void
    {
        $contact = $this->user->account->contacts()->firstWhere('first_name', 'Lynn');
        $contact->delete();

        $this->actingAs($this->user)->get('/contacts?kontakt=new')->assertInertia(fn (Assert $assert) => $assert->where('opened', 'new'));
        $this->actingAs($this->user)->get("/contacts?kontakt={$contact->id}")
            ->assertInertia(fn (Assert $assert) => $assert
                ->where('opened.id', $contact->id)
                ->where('opened.address', '199 Connelly Turnpike')
                ->whereNot('opened.deleted_at', null));

        $foreign = Account::create(['name' => 'Other'])->contacts()->create(['first_name' => 'Not', 'last_name' => 'Yours']);
        $this->actingAs($this->user)->get("/contacts?kontakt={$foreign->id}")->assertNotFound();
    }

    public function test_the_old_create_and_edit_urls_open_the_form_on_the_list(): void
    {
        $contact = $this->user->account->contacts()->firstWhere('first_name', 'Martin');
        $foreign = Account::create(['name' => 'Other'])->contacts()->create(['first_name' => 'Not', 'last_name' => 'Yours']);

        $this->actingAs($this->user)->get('/contacts/create')->assertRedirect('/contacts?kontakt=new');
        $this->actingAs($this->user)->get("/contacts/{$contact->id}/edit")->assertRedirect("/contacts?kontakt={$contact->id}");
        $this->actingAs($this->user)->get("/contacts/{$foreign->id}/edit")->assertNotFound();
    }

    public function test_the_edit_form_saves_the_address_and_notes_and_a_deleted_contact_comes_back(): void
    {
        $contact = $this->user->account->contacts()->firstWhere('first_name', 'Martin');

        $this->actingAs($this->user)->from('/contacts')
            ->put("/contacts/{$contact->id}", [
                'first_name' => 'Martin', 'last_name' => 'Abbott', 'client_id' => null, 'emails' => ['martin.abbott@example.com'],
                'city' => 'Łódź', 'postal_code' => '90-001', 'phone_secondary' => '+48 100 000 104', 'notes' => 'Prefers calls',
            ])
            ->assertRedirect('/contacts');

        $fresh = $contact->fresh();
        $this->assertNull($fresh->client_id);
        $this->assertSame(['Łódź', '90-001', '+48 100 000 104', 'Prefers calls'], [$fresh->city, $fresh->postal_code, $fresh->phone_secondary, $fresh->notes]);

        $this->actingAs($this->user)->from('/contacts')->delete("/contacts/{$contact->id}")->assertRedirect('/contacts');
        $this->assertSoftDeleted($contact);
        $this->actingAs($this->user)->from('/contacts')->put("/contacts/{$contact->id}/restore")->assertRedirect('/contacts');
        $this->assertNotSoftDeleted($contact);
    }
}

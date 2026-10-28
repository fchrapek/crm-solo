<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientsTest extends TestCase
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

        $this->user->account->clients()->createMany([
            [
                'name' => 'Apple',
                'email' => 'info@alpha.test',
                'phone' => '647-943-4400',
                'address' => '1600-120 Bremner Blvd',
                'city' => 'Toronto',
                'region' => 'ON',
                'country' => 'CA',
                'postal_code' => 'M5J 0A8',
            ], [
                'name' => 'Microsoft',
                'email' => 'info@beta.test',
                'phone' => '877-568-2495',
                'address' => 'One Microsoft Way',
                'city' => 'Redmond',
                'region' => 'WA',
                'country' => 'US',
                'postal_code' => '98052',
            ],
        ]);
    }

    public function test_pinning_sets_the_requested_state_and_repeating_it_changes_nothing(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Microsoft');

        foreach ([true, true] as $pinned) {
            $this->actingAs($this->user)
                ->from('/clients')
                ->put("/clients/{$client->id}/pin", ['pinned' => $pinned])
                ->assertRedirect('/clients');

            $this->assertTrue($client->fresh()->is_pinned);
        }
    }

    public function test_unpinning_sets_the_requested_state_and_repeating_it_changes_nothing(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Microsoft');
        $client->update(['is_pinned' => true]);

        foreach ([false, false] as $pinned) {
            $this->actingAs($this->user)
                ->from("/clients/{$client->id}/przeglad")
                ->put("/clients/{$client->id}/pin", ['pinned' => $pinned])
                ->assertRedirect("/clients/{$client->id}/przeglad");

            $this->assertFalse($client->fresh()->is_pinned);
        }
    }

    public function test_pinning_without_an_explicit_state_is_refused_and_changes_nothing(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Microsoft');

        foreach ([[], ['pinned' => null], ['pinned' => 'maybe']] as $payload) {
            $this->actingAs($this->user)
                ->from('/clients')
                ->put("/clients/{$client->id}/pin", $payload)
                ->assertSessionHasErrors('pinned');
        }

        $this->assertFalse($client->fresh()->is_pinned);
    }

    public function test_another_accounts_client_cannot_be_pinned(): void
    {
        $other = Account::create(['name' => 'Other']);
        $stranger = $other->clients()->create(['name' => 'Obcy Przykładowy', 'currency' => 'PLN']);

        $this->actingAs($this->user)
            ->put("/clients/{$stranger->id}/pin", ['pinned' => true])
            ->assertNotFound();

        $this->assertFalse($stranger->fresh()->is_pinned);
    }

    public function test_deleting_a_client_keeps_its_contacts_unless_asked(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $contact = $this->user->account->contacts()->create(['client_id' => $client->id, 'first_name' => 'Ada', 'last_name' => 'Testowa']);

        $this->actingAs($this->user)->delete("/clients/{$client->id}", ['delete_contacts' => false])->assertRedirect('/clients');

        $this->assertSoftDeleted($client);
        $this->assertNotSoftDeleted($contact);
    }

    public function test_deleting_a_client_can_take_its_contacts_along(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $contact = $this->user->account->contacts()->create(['client_id' => $client->id, 'first_name' => 'Ada', 'last_name' => 'Testowa']);

        $this->actingAs($this->user)->delete("/clients/{$client->id}", ['delete_contacts' => true])->assertRedirect('/clients');

        $this->assertSoftDeleted($client);
        $this->assertSoftDeleted($contact);
    }

    public function test_new_client_defaults_to_active_stage(): void
    {
        $response = $this->actingAs($this->user)->post('/clients', [
            'type' => 'business',
            'name' => 'New Lead Ltd',
        ]);

        $client = $this->user->account->clients()->firstWhere('name', 'New Lead Ltd');
        $response->assertRedirect("/clients/{$client->id}/dane");
        $this->assertSame('active', $client->lifecycle_stage);
        $this->assertCount(1, $client->lifecycleEvents);
        $this->assertSame('active', $client->lifecycleEvents->first()->to_stage);
        $this->assertNull($client->lifecycleEvents->first()->from_stage);
    }

    public function test_transition_stage_endpoint_updates_stage_and_writes_event(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $client->update(['lifecycle_stage' => 'paused']);
        $eventsBefore = $client->lifecycleEvents()->count();

        $this->actingAs($this->user)->patch('/clients/'.$client->id.'/stage', [
            'stage' => 'active',
            'note' => "Maintenance offer\nsent via email",
        ])->assertRedirect();

        $client->refresh();
        $this->assertSame('active', $client->lifecycle_stage);
        $this->assertSame($eventsBefore + 1, $client->lifecycleEvents()->count());

        $event = $client->lifecycleEvents()->orderByDesc('id')->first();
        $this->assertSame('paused', $event->from_stage);
        $this->assertSame('active', $event->to_stage);
        $this->assertSame("Maintenance offer\nsent via email", $event->note);
        $this->assertSame($this->user->id, $event->user_id);
    }

    public function test_transition_stage_rejects_invalid_stage(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');

        $this->actingAs($this->user)->patch('/clients/'.$client->id.'/stage', [
            'stage' => 'bogus',
        ])->assertSessionHasErrors('stage');
    }

    public function test_transition_stage_noop_returns_error(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $client->update(['lifecycle_stage' => 'active']);

        $this->actingAs($this->user)->patch('/clients/'.$client->id.'/stage', [
            'stage' => 'active',
        ])->assertSessionHasErrors('stage');
    }

    public function test_same_stage_with_note_creates_event(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $client->update(['lifecycle_stage' => 'active']);
        $eventsBefore = $client->lifecycleEvents()->count();

        $this->actingAs($this->user)->patch('/clients/'.$client->id.'/stage', [
            'stage' => 'active',
            'note' => 'Followed up — waiting on their team to decide.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $client->refresh();
        $this->assertSame('active', $client->lifecycle_stage);
        $this->assertSame($eventsBefore + 1, $client->lifecycleEvents()->count());

        $event = $client->lifecycleEvents()->orderByDesc('id')->first();
        $this->assertSame('active', $event->from_stage);
        $this->assertSame('active', $event->to_stage);
        // The HumanizedText gate normalizes the em dash on write.
        $this->assertSame('Followed up - waiting on their team to decide.', $event->note);
    }

    public function test_update_event_note(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $client->transitionTo('offer_sent');
        $event = $client->lifecycleEvents()->orderByDesc('id')->first();
        $this->assertNull($event->note);

        $this->actingAs($this->user)->patch("/lifecycle-events/{$event->id}/note", [
            'note' => "Sent the maintenance offer.\nAwaiting reply by Friday.",
        ])->assertRedirect();

        $this->assertSame("Sent the maintenance offer.\nAwaiting reply by Friday.", $event->fresh()->note);
    }

    public function test_update_event_note_can_clear(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $client->transitionTo('offer_sent', 'old note');
        $event = $client->lifecycleEvents()->orderByDesc('id')->first();

        $this->actingAs($this->user)->patch("/lifecycle-events/{$event->id}/note", [
            'note' => '',
        ])->assertRedirect();

        $this->assertNull($event->fresh()->note);
    }

    public function test_store_lifecycle_note_creates_log_entry(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');
        $client->update(['lifecycle_stage' => 'active']);
        $eventsBefore = $client->lifecycleEvents()->count();

        $this->actingAs($this->user)->post('/clients/'.$client->id.'/lifecycle-events', [
            'note' => "Fixed the slider on product pages.\nDeployed to prod.",
        ])->assertRedirect()->assertSessionHasNoErrors();

        $client->refresh();
        // No stage change — a same-stage log entry.
        $this->assertSame('active', $client->lifecycle_stage);
        $this->assertSame($eventsBefore + 1, $client->lifecycleEvents()->count());

        $event = $client->lifecycleEvents()->orderByDesc('id')->first();
        $this->assertSame('active', $event->from_stage);
        $this->assertSame('active', $event->to_stage);
        $this->assertSame("Fixed the slider on product pages.\nDeployed to prod.", $event->note);
        $this->assertSame($this->user->id, $event->user_id);
    }

    public function test_store_lifecycle_note_requires_note(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Apple');

        $this->actingAs($this->user)->post('/clients/'.$client->id.'/lifecycle-events', [
            'note' => '',
        ])->assertSessionHasErrors('note');
    }

    public function test_cannot_edit_other_accounts_event_note(): void
    {
        $otherAccount = Account::create(['name' => 'Other Co']);
        $otherClient = $otherAccount->clients()->create(['name' => 'Stranger']);
        $otherClient->transitionTo('offer_sent', 'private');
        $otherEvent = $otherClient->lifecycleEvents()->orderByDesc('id')->first();

        $this->actingAs($this->user)->patch("/lifecycle-events/{$otherEvent->id}/note", [
            'note' => 'attempt',
        ])->assertNotFound();
    }
}

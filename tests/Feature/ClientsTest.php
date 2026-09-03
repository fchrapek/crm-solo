<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_can_view_clients(): void
    {
        // stage=all neutralizes the active-by-default stage filter; the seeded
        // clients are prospects.
        $this->actingAs($this->user)
            ->get('/clients?stage=all')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/index')
                ->has('clients.data', 2)
                ->has('clients.data.0', fn (Assert $assert) => $assert
                    ->has('id')
                    ->has('type')
                    ->where('name', 'Apple')
                    ->where('phone', '647-943-4400')
                    ->where('city', 'Toronto')
                    ->where('is_pinned', false)
                    ->where('deleted_at', null)
                    ->where('lifecycle_stage', 'active')
                    ->has('contacts_count')
                )
                ->has('clients.data.1', fn (Assert $assert) => $assert
                    ->has('id')
                    ->has('type')
                    ->where('name', 'Microsoft')
                    ->where('phone', '877-568-2495')
                    ->where('city', 'Redmond')
                    ->where('is_pinned', false)
                    ->where('deleted_at', null)
                    ->where('lifecycle_stage', 'active')
                    ->has('contacts_count')
                )
            );
    }

    public function test_can_search_for_clients(): void
    {
        $this->actingAs($this->user)
            ->get('/clients?search=Apple&stage=all')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/index')
                ->where('filters.search', 'Apple')
                ->has('clients.data', 1)
                ->has('clients.data.0', fn (Assert $assert) => $assert
                    ->has('id')
                    ->has('type')
                    ->where('name', 'Apple')
                    ->where('phone', '647-943-4400')
                    ->where('city', 'Toronto')
                    ->where('is_pinned', false)
                    ->where('deleted_at', null)
                    ->where('lifecycle_stage', 'active')
                    ->has('contacts_count')
                )
            );
    }

    public function test_cannot_view_deleted_clients(): void
    {
        $this->user->account->clients()->firstWhere('name', 'Microsoft')->delete();

        $this->actingAs($this->user)
            ->get('/clients?stage=all')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/index')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Apple')
            );
    }

    public function test_can_filter_to_view_deleted_clients(): void
    {
        $this->user->account->clients()->firstWhere('name', 'Microsoft')->delete();

        $this->actingAs($this->user)
            ->get('/clients?trashed=with&stage=all')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/index')
                ->has('clients.data', 2)
                ->where('clients.data.0.name', 'Apple')
                ->where('clients.data.1.name', 'Microsoft')
            );
    }

    public function test_can_pin_client(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Microsoft');

        $this->actingAs($this->user)
            ->put("/clients/{$client->id}/pin")
            ->assertRedirect();

        $this->assertTrue($client->fresh()->is_pinned);
    }

    public function test_can_unpin_client(): void
    {
        $client = $this->user->account->clients()->firstWhere('name', 'Microsoft');
        $client->update(['is_pinned' => true]);

        $this->actingAs($this->user)
            ->put("/clients/{$client->id}/pin")
            ->assertRedirect();

        $this->assertFalse($client->fresh()->is_pinned);
    }

    public function test_pinned_clients_appear_first(): void
    {
        $this->user->account->clients()->firstWhere('name', 'Microsoft')->update(['is_pinned' => true]);

        $this->actingAs($this->user)
            ->get('/clients?stage=all')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/index')
                ->where('clients.data.0.name', 'Microsoft')
                ->where('clients.data.0.is_pinned', true)
                ->where('clients.data.1.name', 'Apple')
                ->where('clients.data.1.is_pinned', false)
            );
    }

    public function test_index_defaults_to_active_stage(): void
    {
        // Clients default to active now — park one so the filter has work to do.
        $this->user->account->clients()->firstWhere('name', 'Microsoft')->update(['lifecycle_stage' => 'paused']);

        $this->actingAs($this->user)
            ->get('/clients')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/index')
                ->where('filters.stage', 'active')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Apple')
            );
    }

    public function test_can_filter_by_stage(): void
    {
        $this->user->account->clients()->firstWhere('name', 'Microsoft')->update(['lifecycle_stage' => 'churned']);

        $this->actingAs($this->user)
            ->get('/clients?stage=churned')
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('clients/index')
                ->where('filters.stage', 'churned')
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Microsoft')
            );
    }

    public function test_new_client_defaults_to_active_stage(): void
    {
        $response = $this->actingAs($this->user)->post('/clients', [
            'type' => 'business',
            'name' => 'New Lead Ltd',
        ]);

        $client = $this->user->account->clients()->firstWhere('name', 'New Lead Ltd');
        // Store still lands on the edit page — the natural next step after
        // creating is filling in the record.
        $response->assertRedirect("/clients/{$client->id}/edit");
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

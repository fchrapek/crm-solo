<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ClientRetainersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create([
            'account_id' => $account->id,
            'owner' => true,
        ]);
        $this->client = $account->clients()->create([
            'name' => 'Acme',
            'currency' => 'PLN',
        ]);
    }

    public function test_store_creates_independent_positions_without_closing_others(): void
    {
        // A client can carry several active positions (multi-site / mini-SaaS);
        // adding one must NOT close the others.
        $first = $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/retainers", [
                'label' => 'shop-one.test',
                'monthly_hours' => 0,
                'monthly_fee' => 300,
                'currency' => 'PLN',
                'effective_from' => '2026-03-01',
            ]);

        $first->assertRedirect();
        $this->assertDatabaseCount('client_retainers', 1);

        $second = $this->actingAs($this->user)
            ->post("/clients/{$this->client->id}/retainers", [
                'label' => 'shop-two.test',
                'monthly_hours' => 0,
                'monthly_fee' => 300,
                'invoice_group' => 1,
                'currency' => 'PLN',
                'effective_from' => '2026-04-01',
            ]);

        $second->assertRedirect();
        $this->assertDatabaseCount('client_retainers', 2);

        // Both stay open — independent positions, not a single timeline.
        $this->assertSame(2, $this->client->retainers()->whereNull('effective_to')->count());
    }

    public function test_active_retainer_on_returns_the_row_in_force_for_a_given_date(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'monthly_hours' => 12,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
            'effective_to' => '2026-04-01',
        ]);
        $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'monthly_hours' => 15,
            'currency' => 'PLN',
            'effective_from' => '2026-04-01',
            'effective_to' => null,
        ]);

        $march = $this->client->activeRetainerOn(Carbon::parse('2026-03-15'));
        $may = $this->client->activeRetainerOn(Carbon::parse('2026-05-15'));
        $february = $this->client->activeRetainerOn(Carbon::parse('2026-02-15'));

        $this->assertSame(12.0, (float) $march->monthly_hours);
        $this->assertSame(15.0, (float) $may->monthly_hours);
        $this->assertNull($february);
    }

    public function test_active_retainer_on_picks_the_largest_of_two_overlapping_positions(): void
    {
        $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'label' => 'main site',
            'monthly_hours' => 20,
            'currency' => 'PLN',
            'effective_from' => '2026-01-01',
        ]);
        $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'label' => 'landing page',
            'monthly_hours' => 4,
            'currency' => 'PLN',
            'effective_from' => '2026-05-01',
        ]);

        $this->assertSame('main site', $this->client->activeRetainerOn(Carbon::parse('2026-06-15'))->label);
    }

    public function test_positions_and_invoice_lines_follow_invoice_group_and_sort_order_not_start_dates(): void
    {
        $this->client->update(['month_close_type' => 'maintenance', 'external_ids' => ['infakt' => '10000002']]);
        // Sort orders repeat across groups and two rows share a start date, so only
        // group then sort order gives the expected sequence.
        foreach ([['g2s1', 2, 1, '2026-03-01'], ['g1s1', 1, 1, '2026-03-01'], ['g2s0', 2, 0, '2026-05-01'], ['g1s0', 1, 0, '2026-01-01']] as [$label, $group, $order, $from]) {
            $this->client->retainers()->create([
                'account_id' => $this->client->account_id,
                'label' => $label,
                'invoice_group' => $group,
                'sort_order' => $order,
                'monthly_hours' => 1,
                'monthly_fee' => 100,
                'currency' => 'PLN',
                'effective_from' => $from,
            ]);
        }

        $this->assertSame(
            ['g1s0', 'g1s1', 'g2s0', 'g2s1'],
            $this->client->activeRetainersOn(Carbon::parse('2026-06-15'))->pluck('label')->all(),
        );

        $service = new \App\Services\Integrations\InfaktService(\App\Models\Integration::create([
            'account_id' => $this->client->account_id,
            'provider' => 'infakt',
            'api_key' => 'test-key',
            'is_enabled' => true,
        ]));
        $groups = $service->maintenanceInvoiceGroups($this->client->fresh(), Carbon::parse('2026-06-01'));

        $this->assertSame([1, 2], array_keys($groups));
        $this->assertSame(['g1s0', 'g1s1'], array_column($groups[1]['invoice']['services'], 'name'));
        $this->assertSame(['g2s0', 'g2s1'], array_column($groups[2]['invoice']['services'], 'name'));
    }

    public function test_update_changes_hours_rate_and_notes_in_place(): void
    {
        $retainer = $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'monthly_hours' => 12,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
        ]);

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/retainers/{$retainer->id}", [
                'monthly_hours' => 14,
                'monthly_fee' => 900,
                'overage_hourly_rate' => 165,
                'currency' => 'PLN',
                'notes' => 'bumped after Q1 review',
            ])
            ->assertRedirect();

        $retainer->refresh();
        $this->assertSame(14.0, (float) $retainer->monthly_hours);
        $this->assertSame(900.0, (float) $retainer->monthly_fee);
        $this->assertSame(165.0, (float) $retainer->overage_hourly_rate);
        $this->assertSame('bumped after Q1 review', $retainer->notes);
    }

    public function test_a_fee_only_save_keeps_the_fields_it_did_not_send(): void
    {
        $project = Project::create(['account_id' => $this->client->account_id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $retainer = $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'project_id' => $project->id,
            'monthly_hours' => 12,
            'monthly_fee' => 800,
            'overage_hourly_rate' => 150,
            'rollover_cap_hours' => 24,
            'invoice_group' => 2,
            'vat_symbol' => 'zw',
            'is_active' => false,
            'sort_order' => 3,
            'currency' => 'EUR',
            'label' => 'Care',
            'notes' => 'keep me',
            'effective_from' => '2026-03-01',
        ]);
        $retainer->update(['overage_hourly_rate' => 175]);

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/retainers/{$retainer->id}", ['monthly_hours' => 12, 'monthly_fee' => 900])
            ->assertRedirect();

        $retainer->refresh();
        $this->assertSame(900.0, (float) $retainer->monthly_fee);
        $this->assertSame(175.0, (float) $retainer->overage_hourly_rate);
        $this->assertSame($project->id, $retainer->project_id);
        $this->assertSame(24.0, (float) $retainer->rollover_cap_hours);
        $this->assertSame(2, $retainer->invoice_group);
        $this->assertSame('zw', $retainer->vat_symbol);
        $this->assertFalse($retainer->is_active);
        $this->assertSame(3, $retainer->sort_order);
        $this->assertSame('EUR', $retainer->currency);
        $this->assertSame('Care', $retainer->label);
        $this->assertSame('keep me', $retainer->notes);
        $this->assertSame('2026-03-01', $retainer->effective_from->toDateString());
    }

    public function test_a_field_sent_empty_is_cleared(): void
    {
        $retainer = $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'monthly_hours' => 12,
            'rollover_cap_hours' => 24,
            'notes' => 'old',
            'effective_from' => '2026-03-01',
        ]);

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/retainers/{$retainer->id}", ['monthly_hours' => 12, 'rollover_cap_hours' => null, 'notes' => null])
            ->assertRedirect();

        $retainer->refresh();
        $this->assertNull($retainer->rollover_cap_hours);
        $this->assertNull($retainer->notes);
    }

    public function test_destroy_removes_only_the_targeted_position(): void
    {
        // Positions are independent: deleting one leaves the others untouched
        // (no timeline "reopen").
        $keep = $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'label' => 'keep',
            'monthly_hours' => 0,
            'monthly_fee' => 300,
            'currency' => 'PLN',
            'effective_from' => '2026-03-01',
            'effective_to' => '2026-04-01',
        ]);
        $remove = $this->client->retainers()->create([
            'account_id' => $this->client->account_id,
            'label' => 'remove',
            'monthly_hours' => 0,
            'monthly_fee' => 25,
            'currency' => 'PLN',
            'effective_from' => '2026-04-01',
            'effective_to' => null,
        ]);

        $this->actingAs($this->user)
            ->delete("/clients/{$this->client->id}/retainers/{$remove->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('client_retainers', ['id' => $remove->id]);
        $keep->refresh();
        // Untouched — the deleted row's range does NOT reopen the other.
        $this->assertSame('2026-04-01', $keep->effective_to->toDateString());
    }

    public function test_cannot_touch_a_retainer_from_another_account(): void
    {
        $otherAccount = Account::create(['name' => 'Other']);
        $otherClient = $otherAccount->clients()->create(['name' => 'Other client']);
        $stranger = ClientRetainer::create([
            'account_id' => $otherAccount->id,
            'client_id' => $otherClient->id,
            'monthly_hours' => 10,
            'currency' => 'PLN',
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($this->user)
            ->put("/clients/{$this->client->id}/retainers/{$stranger->id}", [
                'monthly_hours' => 99,
            ])
            ->assertNotFound();
    }
}

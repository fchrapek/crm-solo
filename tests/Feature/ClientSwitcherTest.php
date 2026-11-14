<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ClientSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
    }

    public function test_it_lists_the_pinned_clients_by_name_then_the_ones_opened_last_newest_first(): void
    {
        $zebra = $this->client('Zebra Sp. z o.o.', pinned: true);
        $alfa = $this->client('Alfa', pinned: true);
        $older = $this->client('Older');
        $newer = $this->client('Newer');
        $this->client('Never opened');
        $this->viewed($older, '2026-10-01 10:00:00');
        $this->viewed($newer, '2026-10-05 10:00:00');
        $this->viewed($alfa, '2026-10-06 10:00:00');

        $this->actingAs($this->user)->getJson('/clients/switcher')
            ->assertOk()
            ->assertExactJson([
                'pinned' => [['id' => $alfa->id, 'name' => 'Alfa'], ['id' => $zebra->id, 'name' => 'Zebra']],
                'recent' => [['id' => $newer->id, 'name' => 'Newer'], ['id' => $older->id, 'name' => 'Older']],
            ]);
    }

    public function test_it_keeps_to_the_five_clients_opened_last_and_leaves_archived_ones_out(): void
    {
        foreach (range(1, 6) as $day) {
            $this->viewed($this->client("Client {$day}"), "2026-10-0{$day} 10:00:00");
        }
        $archived = $this->client('Archived');
        $this->viewed($archived, '2026-10-09 10:00:00');
        $archived->delete();

        $names = $this->actingAs($this->user)->getJson('/clients/switcher')->assertOk()->json('recent.*.name');

        $this->assertSame(['Client 6', 'Client 5', 'Client 4', 'Client 3', 'Client 2'], $names);
    }

    public function test_it_shows_only_this_accounts_clients_and_this_users_own_visits(): void
    {
        $mine = $this->client('Mine');
        $colleague = User::factory()->create(['account_id' => $this->account->id]);
        $theirs = $this->client('Opened by a colleague');
        $this->viewed($theirs, '2026-10-05 10:00:00', $colleague);
        $this->viewed($mine, '2026-10-04 10:00:00');

        $other = Account::create(['name' => 'Other']);
        $stranger = User::factory()->create(['account_id' => $other->id, 'owner' => true]);
        $foreign = $this->client('Foreign pinned', pinned: true, account: $other);
        $this->viewed($foreign, '2026-10-06 10:00:00', $stranger);
        // A stray view row for another account's client never leaks its name.
        $this->viewed($foreign, '2026-10-07 10:00:00');

        $this->actingAs($this->user)->getJson('/clients/switcher')
            ->assertOk()
            ->assertExactJson(['pinned' => [], 'recent' => [['id' => $mine->id, 'name' => 'Mine']]]);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->getJson('/clients/switcher')->assertUnauthorized();
    }

    private function client(string $name, bool $pinned = false, ?Account $account = null): Client
    {
        return ($account ?? $this->account)->clients()->create(['name' => $name, 'currency' => 'PLN', 'is_pinned' => $pinned]);
    }

    private function viewed(Client $client, string $at, ?User $user = null): void
    {
        ClientView::create(['user_id' => ($user ?? $this->user)->id, 'client_id' => $client->id, 'viewed_at' => Carbon::parse($at, 'UTC')]);
    }
}

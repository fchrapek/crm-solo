<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->member = User::factory()->create(['account_id' => $this->account->id, 'owner' => false]);
    }

    public function test_a_user_in_another_account_is_not_found(): void
    {
        $stranger = User::factory()->create(['account_id' => Account::create(['name' => 'Other'])->id, 'owner' => true]);

        $this->actingAs($this->owner)->get("/users/{$stranger->id}/edit")->assertNotFound();
        $this->actingAs($this->owner)->put("/users/{$stranger->id}", $this->payload($stranger, ['email' => 'taken@example.com']))->assertNotFound();
        $this->actingAs($this->owner)->delete("/users/{$stranger->id}")->assertNotFound();
        $this->actingAs($this->owner)->put("/users/{$stranger->id}/restore")->assertNotFound();

        $this->assertNotSame('taken@example.com', $stranger->fresh()->email);
        $this->assertNull($stranger->fresh()->deleted_at);
    }

    public function test_a_member_cannot_create_change_or_delete_other_users(): void
    {
        $this->actingAs($this->member)->get('/users/create')->assertForbidden();
        $this->actingAs($this->member)->post('/users', [
            'first_name' => 'New', 'last_name' => 'User', 'email' => 'new@example.com', 'password' => 'a-long-password', 'owner' => false,
        ])->assertForbidden();
        $this->actingAs($this->member)->put("/users/{$this->owner->id}", $this->payload($this->owner, ['email' => 'hijack@example.com', 'password' => 'a-long-password']))->assertForbidden();
        $this->actingAs($this->member)->delete("/users/{$this->owner->id}")->assertForbidden();

        $this->assertSame(2, User::query()->count());
        $this->assertNotSame('hijack@example.com', $this->owner->fresh()->email);
        $this->assertNull($this->owner->fresh()->deleted_at);
    }

    public function test_a_member_can_still_edit_their_own_profile(): void
    {
        $this->actingAs($this->member)
            ->put("/users/{$this->member->id}", $this->payload($this->member, ['first_name' => 'Renamed']))
            ->assertRedirect();

        $this->assertSame('Renamed', $this->member->fresh()->first_name);
    }

    public function test_an_owner_manages_the_account_members(): void
    {
        $this->actingAs($this->owner)
            ->put("/users/{$this->member->id}", $this->payload($this->member, ['first_name' => 'Edited']))
            ->assertRedirect();
        $this->actingAs($this->owner)->delete("/users/{$this->member->id}")->assertRedirect();

        $this->assertSame('Edited', $this->member->fresh()->first_name);
        $this->assertNotNull($this->member->fresh()->deleted_at);
    }

    public function test_the_demo_login_cannot_be_changed_or_deleted_in_demo_mode(): void
    {
        config(['app.demo' => true]);
        $demo = User::factory()->create([
            'account_id' => $this->account->id,
            'email' => User::DEMO_EMAIL,
            'password' => Hash::make('demo-password'),
            'owner' => true,
        ]);

        foreach ([
            ['email' => 'mine@example.com'],
            ['password' => 'a-new-long-password'],
            ['owner' => false],
        ] as $change) {
            $this->actingAs($demo)
                ->put("/users/{$demo->id}", $this->payload($demo, $change))
                ->assertRedirect()
                ->assertSessionHas('error', 'The demo login cannot be changed or deleted.');
        }

        $this->actingAs($demo)->delete("/users/{$demo->id}")
            ->assertSessionHas('error', 'The demo login cannot be changed or deleted.');
        $this->actingAs($this->owner)->delete("/users/{$demo->id}")
            ->assertSessionHas('error', 'The demo login cannot be changed or deleted.');

        $demo->refresh();
        $this->assertSame(User::DEMO_EMAIL, $demo->email);
        $this->assertTrue(Hash::check('demo-password', $demo->password));
        $this->assertTrue((bool) $demo->owner);
        $this->assertNull($demo->deleted_at);
    }

    public function test_the_demo_address_is_an_ordinary_user_outside_demo_mode(): void
    {
        $user = User::factory()->make(['email' => User::DEMO_EMAIL]);

        $this->assertFalse($user->isDemoUser());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides): array
    {
        return array_merge([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'owner' => (bool) $user->owner,
        ], $overrides);
    }
}

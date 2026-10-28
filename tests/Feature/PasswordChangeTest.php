<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a-fresh-long-password';

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);
        $this->owner = User::factory()->create([
            'account_id' => $this->account->id,
            'owner' => true,
            'password' => Hash::make('old-password'),
        ]);
    }

    public function test_changing_your_own_password_without_the_current_one_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->put("/users/{$this->owner->id}", $this->payload($this->owner, ['password' => self::NEW_PASSWORD]))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
    }

    public function test_changing_your_own_password_with_a_wrong_current_one_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->put("/users/{$this->owner->id}", $this->payload($this->owner, [
                'password' => self::NEW_PASSWORD,
                'current_password' => 'not-my-password',
            ]))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
    }

    public function test_guessing_the_current_password_is_cut_off_after_five_tries_a_minute(): void
    {
        $guess = $this->payload($this->owner, [
            'password' => self::NEW_PASSWORD,
            'current_password' => 'not-my-password',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->owner)->put("/users/{$this->owner->id}", $guess)->assertSessionHasErrors('current_password');
        }

        $this->actingAs($this->owner)->put("/users/{$this->owner->id}", array_merge($guess, ['current_password' => 'old-password']))
            ->assertSessionHas('error', __('Sorry, you are making too many requests to our servers.'));
        $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
    }

    public function test_the_right_current_password_changes_it_rotates_the_remember_token_and_keeps_this_session(): void
    {
        $tokenBefore = $this->owner->remember_token;

        $this->actingAs($this->owner)
            ->put("/users/{$this->owner->id}", $this->payload($this->owner, [
                'password' => self::NEW_PASSWORD,
                'current_password' => 'old-password',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $this->owner->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertNotSame($tokenBefore, $fresh->remember_token);

        $this->app['auth']->forgetGuards();
        $this->get('/settings/konto')->assertOk();
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_another_session_of_the_same_user_ends_when_the_password_changes(): void
    {
        $otherDevice = $this->sessionOf($this->owner);

        $this->restoreSession($otherDevice);
        $this->get('/settings/konto')->assertOk();
        $otherDevice = session()->all();

        $this->restoreSession([]);
        $this->actingAs($this->owner)
            ->put("/users/{$this->owner->id}", $this->payload($this->owner, [
                'password' => self::NEW_PASSWORD,
                'current_password' => 'old-password',
            ]))
            ->assertSessionHasNoErrors();

        $this->restoreSession($otherDevice);
        $this->get('/settings/konto')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_owner_sets_another_users_password_without_their_current_one_and_their_remember_token_rotates(): void
    {
        $member = User::factory()->create(['account_id' => $this->account->id, 'owner' => false]);
        $tokenBefore = $member->remember_token;

        $this->actingAs($this->owner)
            ->put("/users/{$member->id}", $this->payload($member, ['password' => self::NEW_PASSWORD]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $member->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertNotSame($tokenBefore, $fresh->remember_token);
    }

    public function test_editing_your_profile_without_a_new_password_needs_no_current_one(): void
    {
        $tokenBefore = $this->owner->remember_token;

        $this->actingAs($this->owner)
            ->put("/users/{$this->owner->id}", $this->payload($this->owner, ['first_name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $this->owner->fresh()->first_name);
        $this->assertSame($tokenBefore, $this->owner->fresh()->remember_token);
    }

    /** @return array<string, mixed> */
    private function sessionOf(User $user): array
    {
        return [Auth::guard('web')->getName() => $user->id];
    }

    /** @param  array<string, mixed>  $data */
    private function restoreSession(array $data): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withSession($data);
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

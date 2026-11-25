<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** My profile: the signed-in person's own settings, the same screen for an owner and a member. */
final class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::factory()->create(['name' => 'Pracownia']);
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true, 'first_name' => 'Ewa', 'last_name' => 'Owner']);
        $this->member = User::factory()->create(['account_id' => $account->id, 'owner' => false, 'first_name' => 'Jan', 'last_name' => 'Member', 'password' => 'old-password']);
    }

    public function test_the_owner_reads_the_profile_the_look_the_helper_the_tasks_and_the_account(): void
    {
        $this->actingAs($this->owner)->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('profile/index')
                ->where('me.first_name', 'Ewa')
                ->where('me.email', $this->owner->email)
                ->where('is_owner', true)
                ->where('account', ['name' => 'Pracownia', 'owners' => []])
                ->where('task_settings', ['warn_full_day' => true, 'show_billed_on_tasks' => false])
                ->where('helper_settings', ['helper_desktop' => true, 'helper_phone' => false])
                ->missing('users')
                ->missing('modules'));
    }

    public function test_a_member_reads_their_own_profile_with_the_owner_named_and_no_billed_switch(): void
    {
        User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true, 'first_name' => 'Obcy']);

        $this->actingAs($this->member)->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('me.first_name', 'Jan')
                ->where('is_owner', false)
                ->where('account', ['name' => 'Pracownia', 'owners' => ['Ewa Owner']])
                ->where('task_settings.show_billed_on_tasks', null));
    }

    public function test_a_member_saves_their_profile_helper_and_tasks_from_it(): void
    {
        $this->actingAs($this->member)->from('/profile')
            ->put("/users/{$this->member->id}", ['first_name' => 'Janek', 'last_name' => 'Member', 'email' => $this->member->email, 'owner' => false])
            ->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->actingAs($this->member)->from('/profile')->patch('/profile/appearance', ['helper_desktop' => false])
            ->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->actingAs($this->member)->from('/profile')->patch('/profile/tasks', ['warn_full_day' => false])
            ->assertSessionHasNoErrors()->assertRedirect('/profile');

        $fresh = $this->member->fresh();
        $this->assertSame('Janek', $fresh->first_name);
        $this->assertFalse($fresh->prefers('helper_desktop'));
        $this->assertFalse($fresh->prefers('warn_full_day'));
        $this->assertTrue($this->owner->fresh()->prefers('helper_desktop'));
        $this->assertTrue($this->owner->fresh()->prefers('warn_full_day'));
    }

    public function test_a_member_changes_nobody_elses_profile(): void
    {
        $this->actingAs($this->member)->put("/users/{$this->owner->id}", ['first_name' => 'Zmieniona', 'last_name' => 'Owner', 'email' => $this->owner->email, 'owner' => true])
            ->assertForbidden();

        $this->assertSame('Ewa', $this->owner->fresh()->first_name);
    }

    public function test_the_old_write_paths_under_settings_are_gone(): void
    {
        $this->actingAs($this->member)->patch('/settings/tasks', ['warn_full_day' => false])->assertNotFound();
        $this->actingAs($this->member)->patch('/settings/appearance', ['helper_desktop' => false])->assertNotFound();

        $this->assertTrue($this->member->fresh()->prefers('warn_full_day'));
        $this->assertTrue($this->member->fresh()->prefers('helper_desktop'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }
}

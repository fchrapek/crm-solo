<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Helper on a computer and on a phone: two preferences per user, saved to the account from Settings, Appearance. */
final class HelperSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
    }

    public function test_the_helper_is_on_for_a_computer_and_off_for_a_phone_until_the_user_chooses(): void
    {
        $this->actingAs($this->owner)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('helper_settings.helper_desktop', true)
            ->where('helper_settings.helper_phone', false)
            ->where('auth.user.helper', ['desktop' => true, 'phone' => false]));
    }

    public function test_each_switch_saves_on_its_own_and_every_page_shares_it(): void
    {
        $this->actingAs($this->owner)->from('/profile')->patch('/profile/appearance', ['helper_phone' => true])
            ->assertRedirect('/profile')->assertSessionHasNoErrors();
        $this->assertTrue($this->owner->fresh()->prefers('helper_phone'));
        $this->assertTrue($this->owner->fresh()->prefers('helper_desktop'));

        $this->actingAs($this->owner)->patch('/profile/appearance', ['helper_desktop' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->owner->fresh()->prefers('helper_desktop'));
        $this->assertTrue($this->owner->fresh()->prefers('helper_phone'));

        $this->actingAs($this->owner->fresh())->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user.helper', ['desktop' => false, 'phone' => true]));
        $this->actingAs($this->owner->fresh())->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('helper_settings.helper_desktop', false)
            ->where('helper_settings.helper_phone', true));
    }

    public function test_a_switch_takes_only_a_yes_or_no_and_the_appearance_save_touches_no_other_preference(): void
    {
        $this->actingAs($this->owner)->patch('/profile/appearance', ['helper_phone' => 'often'])->assertSessionHasErrors('helper_phone');
        $this->actingAs($this->owner)->patch('/profile/appearance', ['helper_desktop' => false, 'warn_full_day' => false])->assertSessionHasNoErrors();

        $this->assertFalse($this->owner->fresh()->prefers('helper_phone'));
        $this->assertFalse($this->owner->fresh()->prefers('helper_desktop'));
        $this->assertTrue($this->owner->fresh()->prefers('warn_full_day'));
    }

    public function test_a_member_saves_their_own_helper_and_nobody_elses(): void
    {
        $member = User::factory()->create(['account_id' => $this->owner->account_id, 'owner' => false]);

        $this->actingAs($member)->get('/profile')->assertOk();
        $this->actingAs($member)->patch('/profile/appearance', ['helper_phone' => true])->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->prefers('helper_phone'));
        $this->assertFalse($this->owner->fresh()->prefers('helper_phone'));
    }
}

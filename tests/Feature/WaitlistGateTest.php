<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Closed mode (APP_CLOSED): guests get the waitlist splash instead of the app,
 * existing users still log in, and a signup is a lead in the configured
 * account's funnel, never a user.
 */
final class WaitlistGateTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Owner account']);
        $this->owner = User::factory()->create([
            'account_id' => $this->account->id,
            'owner' => true,
            'email' => 'owner@example.com',
            'password' => bcrypt('secret-password'),
        ]);

        config([
            'waitlist.closed' => true,
            'waitlist.account_id' => $this->account->id,
            'waitlist.pipeline' => null,
            'services.turnstile.secret' => null,
        ]);
    }

    public function test_an_open_app_sends_guests_to_login_and_has_no_splash(): void
    {
        config(['waitlist.closed' => false]);

        $this->get('/')->assertRedirect('/login');
        $this->get('/czesc')->assertNotFound();
        $this->post('/czesc', ['email' => 'guest@example.com'])->assertNotFound();

        $this->assertSame(0, Lead::count());
    }

    public function test_a_closed_app_sends_guests_from_any_page_to_the_splash(): void
    {
        $this->get('/')->assertRedirect('/czesc');
        $this->get('/clients')->assertRedirect('/czesc');
        $this->get('/waitlist')->assertNotFound();

        $this->get('/czesc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/czesc')
                ->where('joined', false)
                ->where('turnstileSiteKey', null));
    }

    public function test_existing_users_still_log_in_while_closed(): void
    {
        $this->get('/login')->assertOk();

        $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'secret-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_a_guest_sent_to_the_splash_returns_to_the_page_they_asked_for_after_login(): void
    {
        $this->get('/clients')->assertRedirect('/czesc');

        $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'secret-password',
        ])->assertRedirect('/clients');
    }

    public function test_signed_in_users_never_see_the_splash(): void
    {
        $this->actingAs($this->owner)->get('/czesc')->assertRedirect('/');
        $this->actingAs($this->owner)->post('/czesc', ['email' => 'x@example.com'])->assertRedirect('/');

        $this->assertSame(0, Lead::count());
    }

    public function test_there_is_no_registration_while_closed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertSame(1, User::count());
    }

    public function test_a_signup_becomes_a_waitlist_lead_in_the_configured_account(): void
    {
        $this->post('/czesc', ['email' => 'Ada@Example.com', 'name' => '  Ada  '])
            ->assertRedirect('/czesc')
            ->assertSessionHas('waitlist_joined', true);

        $lead = Lead::sole();
        $this->assertSame($this->account->id, $lead->account_id);
        $this->assertSame('waitlist', $lead->source);
        $this->assertSame(Lead::pipelines()[0], $lead->pipeline);
        $this->assertSame(Lead::entryStage($lead->pipeline, 'waitlist'), $lead->stage);
        $this->assertSame('Ada', $lead->name);
        $this->assertSame('ada@example.com', $lead->email);
        $this->assertSame(1, $lead->stageEvents()->whereNull('from_stage')->count());

        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_the_thank_you_state_shows_after_a_signup(): void
    {
        $this->followingRedirects()
            ->post('/czesc', ['email' => 'ada@example.com'])
            ->assertInertia(fn ($page) => $page->component('auth/czesc')->where('joined', true));
    }

    public function test_a_signup_without_a_name_is_named_after_its_email(): void
    {
        $this->post('/czesc', ['email' => 'ada@example.com']);

        $this->assertSame('ada@example.com', Lead::sole()->name);
    }

    public function test_the_configured_pipeline_is_used(): void
    {
        $pipeline = Lead::pipelines()[1];
        config(['waitlist.pipeline' => $pipeline]);

        $this->post('/czesc', ['email' => 'ada@example.com']);

        $this->assertSame($pipeline, Lead::sole()->pipeline);
    }

    public function test_a_repeated_signup_keeps_one_lead_and_gets_the_same_answer(): void
    {
        $first = $this->post('/czesc', ['email' => 'ada@example.com']);
        $second = $this->post('/czesc', ['email' => 'ADA@example.com ', 'name' => 'Someone else']);

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $second->assertSessionHas('waitlist_joined', true);

        $lead = Lead::sole();
        $this->assertSame('ada@example.com', $lead->name);
    }

    public function test_a_lead_deleted_in_the_crm_is_not_brought_back_by_a_new_signup(): void
    {
        $this->post('/czesc', ['email' => 'ada@example.com']);
        Lead::sole()->delete();

        $this->post('/czesc', ['email' => 'ada@example.com'])->assertSessionHas('waitlist_joined', true);

        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Lead::withTrashed()->count());
    }

    public function test_dedupe_looks_only_at_waitlist_leads_of_the_configured_account(): void
    {
        $other = Account::create(['name' => 'Someone else']);
        Lead::create(['account_id' => $other->id, 'pipeline' => Lead::pipelines()[0], 'name' => 'Theirs', 'source' => 'waitlist', 'email' => 'ada@example.com']);
        Lead::create(['account_id' => $this->account->id, 'pipeline' => Lead::pipelines()[0], 'name' => 'Client lead', 'source' => 'referral', 'email' => 'ada@example.com']);

        $this->post('/czesc', ['email' => 'ada@example.com']);

        $this->assertSame(1, Lead::where('account_id', $this->account->id)->where('source', 'waitlist')->count());
        $this->assertSame(1, Lead::where('account_id', $other->id)->count());
    }

    public function test_a_filled_honeypot_gets_the_thank_you_and_no_lead(): void
    {
        $this->post('/czesc', ['email' => 'bot@example.com', 'website' => 'https://spam.example'])
            ->assertRedirect('/czesc')
            ->assertSessionHas('waitlist_joined', true);

        $this->assertSame(0, Lead::count());
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->from('/czesc')
            ->post('/czesc', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Lead::count());
    }

    public function test_signups_are_rate_limited_per_visitor(): void
    {
        config(['waitlist.per_minute' => 2]);

        $this->post('/czesc', ['email' => 'one@example.com']);
        $this->post('/czesc', ['email' => 'two@example.com']);
        $this->from('/czesc')
            ->post('/czesc', ['email' => 'three@example.com'])
            ->assertRedirect('/czesc')
            ->assertSessionHas('error');

        $this->assertSame(['one@example.com', 'two@example.com'], Lead::orderBy('id')->pluck('email')->all());
    }

    public function test_turnstile_is_enforced_when_configured(): void
    {
        config(['services.turnstile.secret' => 'test-secret', 'services.turnstile.site_key' => 'test-sitekey']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->get('/czesc')->assertInertia(fn ($page) => $page->where('turnstileSiteKey', 'test-sitekey'));

        $this->from('/czesc')
            ->post('/czesc', ['email' => 'ada@example.com'])
            ->assertSessionHasErrors('cf-turnstile-response');
        $this->from('/czesc')
            ->post('/czesc', ['email' => 'ada@example.com', 'cf-turnstile-response' => 'bad-token'])
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertSame(0, Lead::count());
    }

    public function test_a_verified_turnstile_token_lets_the_signup_through(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->post('/czesc', ['email' => 'ada@example.com', 'cf-turnstile-response' => 'good-token'])
            ->assertSessionHas('waitlist_joined', true);

        $this->assertSame(1, Lead::count());
    }

    public function test_a_missing_account_fails_loudly_instead_of_thanking_the_visitor(): void
    {
        config(['waitlist.account_id' => 999999]);

        $this->post('/czesc', ['email' => 'ada@example.com'])->assertStatus(503);

        $this->assertSame(0, Lead::count());
    }

    public function test_a_missing_account_shows_on_the_splash_before_anyone_types_an_email(): void
    {
        config(['waitlist.account_id' => 999999]);

        $this->get('/czesc')->assertStatus(503);
    }

    public function test_a_missing_account_in_production_renders_the_inertia_error_page_for_a_guest(): void
    {
        $this->app['env'] = 'production';
        config(['waitlist.account_id' => 999999]);

        $this->get('/czesc')
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page
                ->component('error', false)
                ->where('status', 503)
                ->where('auth.user', null));
    }

    public function test_an_unknown_pipeline_fails_loudly_instead_of_thanking_the_visitor(): void
    {
        config(['waitlist.pipeline' => 'no-such-pipeline']);

        $this->get('/czesc')->assertStatus(503);
        $this->post('/czesc', ['email' => 'ada@example.com'])->assertStatus(503);

        $this->assertSame(0, Lead::count());
    }

    public function test_a_signup_cannot_choose_its_account_source_stage_or_pipeline(): void
    {
        $other = Account::create(['name' => 'Someone else']);

        $this->post('/czesc', [
            'email' => 'ada@example.com',
            'account_id' => $other->id,
            'source' => 'referral',
            'stage' => 'won',
            'pipeline' => Lead::pipelines()[1],
        ])->assertSessionHas('waitlist_joined', true);

        $lead = Lead::sole();
        $this->assertSame($this->account->id, $lead->account_id);
        $this->assertSame('waitlist', $lead->source);
        $this->assertSame(Lead::pipelines()[0], $lead->pipeline);
        $this->assertSame(Lead::entryStage(Lead::pipelines()[0], 'waitlist'), $lead->stage);
    }

    public function test_an_array_in_the_honeypot_gets_the_thank_you_and_no_lead(): void
    {
        $this->post('/czesc', ['email' => 'bot@example.com', 'website' => ['spam']])
            ->assertRedirect('/czesc')
            ->assertSessionHas('waitlist_joined', true);

        $this->assertSame(0, Lead::count());
    }

    public function test_two_signups_racing_past_the_lookup_still_leave_one_lead(): void
    {
        // A concurrent request inserts the same address right after this one's lookup missed.
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if ($raced || ! str_contains($query->sql, 'LOWER(email)')) {
                return;
            }
            $raced = true;
            $this->app->make(\App\Services\Leads\LeadCapture::class)->captureOnce($this->account, [
                'name' => 'First',
                'source' => 'waitlist',
                'email' => 'ada@example.com',
            ]);
        });

        $this->post('/czesc', ['email' => 'ada@example.com'])->assertSessionHas('waitlist_joined', true);

        $this->assertTrue($raced);
        $lead = Lead::sole();
        $this->assertSame('First', $lead->name);
        $this->assertSame(1, $lead->stageEvents()->whereNull('from_stage')->count());
    }
}

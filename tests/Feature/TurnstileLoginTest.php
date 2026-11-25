<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Turnstile on the login form: the gate activates only when TURNSTILE_SECRET
 * is configured, verifies via canonical siteverify, and fails closed on
 * anything but success === true.
 */
final class TurnstileLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create([
            'account_id' => $account->id,
            'owner' => true,
            'password' => bcrypt('secret-password'),
        ]);
    }

    public function test_login_works_without_token_when_turnstile_is_not_configured(): void
    {
        config(['services.turnstile.secret' => null]);

        $this->post('/login', [
            'email' => $this->user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_login_page_exposes_the_sitekey_only_when_configured(): void
    {
        config(['services.turnstile.secret' => null]);
        $this->get('/login')->assertInertia(fn ($page) => $page->where('turnstileSiteKey', null));

        config(['services.turnstile.secret' => 'test-secret', 'services.turnstile.site_key' => 'test-sitekey']);
        $this->get('/login')->assertInertia(fn ($page) => $page->where('turnstileSiteKey', 'test-sitekey'));
    }

    public function test_login_without_token_is_rejected_when_configured(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake();

        $this->from('/login')->post('/login', [
            'email' => $this->user->email,
            'password' => 'secret-password',
        ])->assertSessionHasErrors('cf-turnstile-response');

        $this->assertGuest();
        // No token -> the rule fails before any siteverify call happens.
        Http::assertNothingSent();
    }

    public function test_login_is_rejected_when_siteverify_says_no(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
        ]);

        $this->from('/login')->post('/login', [
            'email' => $this->user->email,
            'password' => 'secret-password',
            'cf-turnstile-response' => 'a-token',
        ])->assertSessionHasErrors('cf-turnstile-response');

        $this->assertGuest();
    }

    public function test_the_sign_in_limit_stands_before_the_turnstile_check(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
        $attempt = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->from('/login')
            ->post('/login', ['email' => 'guess@example.com', 'password' => 'wrong', 'cf-turnstile-response' => 'a-token']);

        foreach (range(1, 10) as $ignored) {
            $attempt('198.51.100.1')->assertSessionHasErrors('cf-turnstile-response');
        }
        $attempt('198.51.100.1')->assertRedirect('/login')->assertSessionHas('error')->assertSessionDoesntHaveErrors('cf-turnstile-response');
        Http::assertSentCount(10);

        $attempt('198.51.100.2')->assertSessionHasErrors('cf-turnstile-response');
    }

    public function test_login_is_rejected_when_siteverify_errors(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response('upstream error', 502),
        ]);

        $this->from('/login')->post('/login', [
            'email' => $this->user->email,
            'password' => 'secret-password',
            'cf-turnstile-response' => 'a-token',
        ])->assertSessionHasErrors('cf-turnstile-response');

        $this->assertGuest();
    }

    public function test_login_succeeds_with_a_verified_token(): void
    {
        config(['services.turnstile.secret' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->post('/login', [
            'email' => $this->user->email,
            'password' => 'secret-password',
            'cf-turnstile-response' => 'a-valid-token',
        ])->assertRedirect('/');

        $this->assertAuthenticated();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'challenges.cloudflare.com/turnstile/v0/siteverify')
                && $request['secret'] === 'test-secret'
                && $request['response'] === 'a-valid-token'
                && isset($request['remoteip']);
        });
    }
}

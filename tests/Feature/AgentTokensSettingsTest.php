<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentToken;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ustawienia · Agenci: the owner issues, re-scopes and revokes the account's
 * agent tokens. The plain token is answered once, in the create response.
 */
final class AgentTokensSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->member = User::factory()->create(['account_id' => $account->id, 'owner' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_owner_creates_a_token_that_is_answered_once_and_stored_as_a_hash(): void
    {
        $response = $this->create(['name' => 'claude-code macbook', 'abilities' => ['read', 'write:notes'], 'expires' => '30'])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private');

        $plain = (string) $response->json('token');
        [$id, $secret] = explode('|', $plain, 2);
        $token = AgentToken::query()->sole();

        $this->assertSame((int) $id, $token->id);
        $this->assertSame(hash('sha256', $secret), $token->token);
        $this->assertSame(['read', 'write:notes'], $token->abilities);
        $this->assertSame($this->owner->account_id, $token->account_id);
        $this->assertTrue($token->tokenable->is($this->owner));
        $this->assertSame('2026-11-07', $token->expires_at->toDateString());
        $this->assertNull(session('token'));

        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->post('/agent/verb', ['verb' => 'today', 'args' => ['--json']])->assertOk();
    }

    public function test_the_tab_lists_tokens_without_ever_sending_the_token_or_its_hash(): void
    {
        $plain = (string) $this->create(['name' => 'codex', 'abilities' => ['read'], 'expires' => '90'])->json('token');

        $page = $this->actingAs($this->owner)->get('/settings/agenci')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/index')
                ->where('tab', 'agenci')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'codex')
                ->where('tokens.0.abilities', ['read'])
                ->where('tokens.0.expires_in_days', 90)
                ->where('tokens.0.last_used', null)
                ->missing('tokens.0.token'));

        $body = $page->getContent();
        $this->assertStringNotContainsString(explode('|', $plain, 2)[1], $body);
        $this->assertStringNotContainsString(AgentToken::query()->sole()->token, $body);
    }

    public function test_creating_a_token_needs_the_owners_current_password(): void
    {
        $this->create(['name' => 'x', 'abilities' => ['read'], 'expires' => '30', 'current_password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_an_unknown_ability_or_a_token_without_read_is_refused(): void
    {
        $this->create(['name' => 'x', 'abilities' => ['read', 'write:everything'], 'expires' => '30'])->assertUnprocessable();
        $this->create(['name' => 'x', 'abilities' => ['write:notes'], 'expires' => '30'])->assertUnprocessable()->assertJsonValidationErrors('abilities');
        $this->create(['name' => 'x', 'abilities' => ['read'], 'expires' => '7'])->assertUnprocessable();

        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_a_member_cannot_see_the_tab_or_touch_tokens(): void
    {
        $token = $this->issue($this->owner);

        $this->actingAs($this->member)->get('/settings/agenci')->assertForbidden();
        $this->actingAs($this->member)->postJson('/settings/agent-tokens', ['name' => 'x', 'abilities' => ['read'], 'expires' => '30', 'current_password' => 'password'])->assertForbidden();
        $this->actingAs($this->member)->patch("/settings/agent-tokens/{$token->id}", ['abilities' => ['read', 'write:time']])->assertForbidden();
        $this->actingAs($this->member)->delete("/settings/agent-tokens/{$token->id}")->assertForbidden();

        $this->assertSame(['read'], $token->fresh()->abilities);
        $this->assertNull($token->fresh()->revoked_at);
        $this->actingAs($this->member)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('summary.tokens', null));
    }

    public function test_another_accounts_token_is_not_found(): void
    {
        $stranger = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
        $token = $this->issue($stranger);

        $this->actingAs($this->owner)->patch("/settings/agent-tokens/{$token->id}", ['abilities' => ['read', 'write:time']])->assertNotFound();
        $this->actingAs($this->owner)->delete("/settings/agent-tokens/{$token->id}")->assertNotFound();
        $this->actingAs($this->owner)->get('/settings/agenci')->assertInertia(fn (Assert $page) => $page->has('tokens', 0));

        $this->assertSame(['read'], $token->fresh()->abilities);
        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_the_owner_changes_abilities_and_a_removed_one_stops_working(): void
    {
        $issued = $this->owner->createToken('agent', ['read', 'write:notes'], now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $this->owner->account_id])->save();
        $token = AgentToken::query()->sole();

        $this->actingAs($this->owner)->patch("/settings/agent-tokens/{$token->id}", ['abilities' => ['read', 'write:time']])->assertRedirect();
        $this->assertSame(['read', 'write:time'], $token->fresh()->abilities);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$issued->plainTextToken])
            ->post('/agent/verb', ['verb' => 'note', 'args' => ['Acme', 'hello']])
            ->assertForbidden();
    }

    public function test_abilities_without_read_are_refused_and_a_revoked_token_keeps_its_abilities(): void
    {
        $token = $this->issue($this->owner);

        $this->actingAs($this->owner)->patch("/settings/agent-tokens/{$token->id}", ['abilities' => ['write:time']])->assertSessionHasErrors('abilities');
        $this->assertSame(['read'], $token->fresh()->abilities);

        $token->forceFill(['revoked_at' => now()])->save();
        $this->actingAs($this->owner)->patch("/settings/agent-tokens/{$token->id}", ['abilities' => ['read', 'write:time']])->assertSessionHas('error');
        $this->assertSame(['read'], $token->fresh()->abilities);
    }

    public function test_revoking_stops_the_token_at_once_and_drops_it_from_the_list(): void
    {
        $issued = $this->owner->createToken('agent', ['read'], now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $this->owner->account_id])->save();
        $token = AgentToken::query()->sole();

        $this->actingAs($this->owner)->delete("/settings/agent-tokens/{$token->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->actingAs($this->owner)->get('/settings/agenci')->assertInertia(fn (Assert $page) => $page->has('tokens', 0));

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$issued->plainTextToken])
            ->post('/agent/verb', ['verb' => 'today', 'args' => []])
            ->assertUnauthorized();
    }

    public function test_the_token_endpoints_are_absent_on_the_demo(): void
    {
        config(['app.demo' => true]);
        $token = $this->issue($this->owner);

        $this->create(['name' => 'x', 'abilities' => ['read'], 'expires' => '30'])->assertNotFound();
        $this->actingAs($this->owner)->patch("/settings/agent-tokens/{$token->id}", ['abilities' => ['read', 'write:time']])->assertNotFound();
        $this->actingAs($this->owner)->delete("/settings/agent-tokens/{$token->id}")->assertNotFound();
        $this->assertSame(1, AgentToken::query()->count());
    }

    public function test_a_json_failure_answers_json_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        $request = ['name' => 'x', 'abilities' => ['read'], 'expires' => '30', 'current_password' => 'password'];

        $this->actingAs($this->owner)->postJson('/settings/agent-tokens', $request)->assertStatus(419)->assertJsonStructure(['message']);

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs($this->member)->postJson('/settings/agent-tokens', $request)->assertForbidden()->assertJsonStructure(['message']);
    }

    /** @param  array<string, mixed>  $data */
    private function create(array $data): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/settings/agent-tokens', [...$data, 'current_password' => $data['current_password'] ?? 'password']);
    }

    private function issue(User $user): AgentToken
    {
        $issued = $user->createToken('agent', [AgentAbilities::READ], now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $user->account_id])->save();

        return AgentToken::query()->findOrFail($issued->accessToken->getKey());
    }
}

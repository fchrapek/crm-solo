<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A blank field keeps a credential, so clearing one is its own action:
 * disconnect removes the provider's credentials and turns it off.
 */
final class IntegrationDisconnectTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
    }

    public function test_disconnect_removes_every_credential_and_turns_the_integration_off(): void
    {
        $integration = $this->trello($this->account);

        $this->actingAs($this->user)
            ->delete('/integrations/trello')
            ->assertRedirect('/integrations/trello')
            ->assertSessionHas('success');

        $fresh = $integration->fresh();
        $this->assertNull($fresh->api_key);
        $this->assertArrayNotHasKey('trello_api_key', $fresh->settings);
        $this->assertSame('kept', $fresh->settings['other']);
        $this->assertFalse($fresh->is_enabled);
        $this->assertFalse($fresh->isConfigured());
        $this->assertNull(DB::table('integrations')->where('id', $integration->id)->value('api_key'));
    }

    public function test_disconnect_removes_a_credential_this_app_key_cannot_read(): void
    {
        $integration = $this->trello($this->account);
        $foreign = $this->encryptedUnderAnotherKey('old-app-key');
        $raw = json_decode((string) DB::table('integrations')->where('id', $integration->id)->value('settings'), true);
        DB::table('integrations')->where('id', $integration->id)->update([
            'settings' => json_encode([...$raw, 'trello_api_key' => $foreign]),
            'api_key' => $foreign,
        ]);
        $this->assertSame(['api_key', 'trello_api_key'], $integration->fresh()->unreadableSecrets());

        $this->actingAs($this->user)->delete('/integrations/trello')->assertRedirect();

        $stored = json_decode((string) DB::table('integrations')->where('id', $integration->id)->value('settings'), true);
        $this->assertArrayNotHasKey('trello_api_key', $stored);
        $this->assertSame([], $integration->fresh()->unreadableSecrets());
    }

    public function test_disconnect_touches_only_the_signed_in_account(): void
    {
        $other = Account::create(['name' => 'Other']);
        $theirs = $this->trello($other);
        $this->trello($this->account);

        $this->actingAs($this->user)->delete('/integrations/trello')->assertRedirect();

        $this->assertSame('token', $theirs->fresh()->api_key);
        $this->assertTrue($theirs->fresh()->isConfigured());
    }

    public function test_disconnect_of_an_unknown_provider_is_not_found(): void
    {
        $this->actingAs($this->user)->delete('/integrations/clockify')->assertNotFound();
    }

    public function test_a_guest_cannot_disconnect(): void
    {
        $integration = $this->trello($this->account);

        $this->delete('/integrations/trello')->assertRedirect('/login');

        $this->assertTrue($integration->fresh()->isConfigured());
    }

    private function trello(Account $account): Integration
    {
        return Integration::create([
            'account_id' => $account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'token',
            'settings' => ['trello_api_key' => 'app-key', 'other' => 'kept'],
        ]);
    }

    private function encryptedUnderAnotherKey(string $value): string
    {
        $current = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $encrypted = Crypt::encryptString($value);
        config(['app.key' => $current]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        return $encrypted;
    }
}

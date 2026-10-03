<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\UnreadableIntegrationCredentials;
use App\Models\Account;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\InfaktService;
use App\Services\Integrations\Trello\TrelloService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Credentials encrypted under one APP_KEY and read under another are never
 * taken for plain text: they read as missing, survive saves byte for byte,
 * stop the integration with a clear error, and come back with the old key.
 */
final class IntegrationAppKeyChangeTest extends TestCase
{
    use RefreshDatabase;

    private string $keyA;

    private string $keyB;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyA = 'base64:'.base64_encode(random_bytes(32));
        $this->keyB = 'base64:'.base64_encode(random_bytes(32));
        $this->useAppKey($this->keyA);

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
    }

    public function test_credentials_from_another_key_survive_a_save_and_return_with_their_key(): void
    {
        $integration = $this->trello();
        $rawBefore = $this->raw($integration->id);

        $this->useAppKey($this->keyB);
        $underB = $integration->fresh();

        $this->assertArrayNotHasKey('trello_api_key', $underB->settings, 'ciphertext is never read as the credential');
        $this->assertNull($underB->api_key);
        $this->assertSame(['api_key', 'trello_api_key'], $underB->unreadableSecrets());
        $this->assertFalse($underB->isConfigured());

        $this->actingAs($this->user)
            ->get('/integrations/trello')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('integration.has_unreadable_credentials', true)
                ->where('integration.has_trello_api_key', false));

        $this->actingAs($this->user)
            ->put('/integrations/trello', ['is_enabled' => false, 'settings' => ['other' => 'changed']])
            ->assertRedirect();

        $rawAfter = $this->raw($integration->id);
        $this->assertSame($rawBefore['api_key'], $rawAfter['api_key']);
        $this->assertSame($rawBefore['settings']['trello_api_key'], $rawAfter['settings']['trello_api_key']);
        $this->assertSame('x', $rawAfter['settings']['other'], 'An undeclared settings key is not writable from the form.');

        $this->useAppKey($this->keyA);
        $back = $integration->fresh();
        $this->assertSame('app-key-A', $back->settings['trello_api_key']);
        $this->assertSame('token-A', $back->api_key);
        $this->assertSame([], $back->unreadableSecrets());
    }

    public function test_an_integration_with_unreadable_credentials_refuses_to_run(): void
    {
        $trello = $this->trello();
        $infakt = Integration::create(['account_id' => $this->account->id, 'provider' => 'infakt', 'is_enabled' => true, 'api_key' => 'infakt-key']);

        $this->useAppKey($this->keyB);

        foreach ([fn () => new TrelloService($trello->fresh()), fn () => new InfaktService($infakt->fresh())] as $make) {
            try {
                $make();
                $this->fail('The service started with unreadable credentials.');
            } catch (UnreadableIntegrationCredentials $e) {
                $this->assertStringContainsString('cannot be decrypted with the current APP_KEY', $e->getMessage());
            }
        }
    }

    public function test_a_new_key_entered_under_the_current_app_key_replaces_the_unreadable_one(): void
    {
        $integration = $this->trello();
        $this->useAppKey($this->keyB);

        $this->actingAs($this->user)
            ->put('/integrations/trello', ['is_enabled' => true, 'api_key' => 'token-B', 'trello_api_key' => 'app-key-B'])
            ->assertRedirect();

        $fresh = $integration->fresh();
        $this->assertSame('app-key-B', $fresh->settings['trello_api_key']);
        $this->assertSame('token-B', $fresh->api_key);
        $this->assertSame([], $fresh->unreadableSecrets());
        $this->assertTrue($fresh->isConfigured());
    }

    public function test_the_migration_leaves_ciphertext_from_another_key_alone_both_ways(): void
    {
        $integration = $this->trello();
        $before = $this->raw($integration->id)['settings']['trello_api_key'];
        $this->useAppKey($this->keyB);

        $migration = $this->migration();
        $migration->up();
        $this->assertSame($before, $this->raw($integration->id)['settings']['trello_api_key']);
        $migration->down();
        $this->assertSame($before, $this->raw($integration->id)['settings']['trello_api_key']);
    }

    private function trello(): Integration
    {
        return Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'token-A',
            'settings' => ['trello_api_key' => 'app-key-A', 'other' => 'x'],
        ]);
    }

    /**
     * @return array{api_key: string|null, settings: array<string, mixed>}
     */
    private function raw(int $id): array
    {
        $row = DB::table('integrations')->where('id', $id)->first();

        return ['api_key' => $row->api_key, 'settings' => json_decode((string) $row->settings, true)];
    }

    private function useAppKey(string $key): void
    {
        config(['app.key' => $key]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_01_130100_encrypt_secret_integration_settings.php');
    }
}

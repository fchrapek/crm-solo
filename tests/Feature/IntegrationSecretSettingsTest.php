<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class IntegrationSecretSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Acc']);
    }

    public function test_the_trello_app_key_is_stored_encrypted_and_read_in_plain_text(): void
    {
        $integration = $this->trello(['trello_api_key' => 'plain-app-key', 'other' => 'visible']);

        $raw = $this->rawSettings($integration->id);
        $this->assertNotSame('plain-app-key', $raw['trello_api_key']);
        $this->assertSame('plain-app-key', Crypt::decryptString($raw['trello_api_key']));
        $this->assertSame('visible', $raw['other']);

        $fresh = $integration->fresh();
        $this->assertSame('plain-app-key', $fresh->settings['trello_api_key']);
        $this->assertTrue($fresh->isConfigured());
    }

    public function test_saving_other_fields_keeps_the_stored_ciphertext(): void
    {
        $integration = $this->trello(['trello_api_key' => 'plain-app-key']);
        $before = $this->rawSettings($integration->id)['trello_api_key'];

        $integration->fresh()->update(['last_synced_at' => now(), 'settings' => [...$integration->settings, 'other' => 'x']]);

        $this->assertSame($before, $this->rawSettings($integration->id)['trello_api_key']);
    }

    public function test_a_row_written_before_encryption_still_reads(): void
    {
        $integration = $this->trello([]);
        $this->writeRawSettings($integration->id, ['trello_api_key' => 'legacy-plain-key']);

        $this->assertSame('legacy-plain-key', $integration->fresh()->settings['trello_api_key']);
        $this->assertTrue($integration->fresh()->isConfigured());
    }

    public function test_the_migration_encrypts_plain_keys_once_and_reverses(): void
    {
        $legacy = $this->trello([]);
        $this->writeRawSettings($legacy->id, ['trello_api_key' => 'legacy-plain-key', 'other' => 'kept']);
        $alreadyEncrypted = $this->trello(['trello_api_key' => 'new-key'], Account::create(['name' => 'Other']));
        $encryptedBefore = $this->rawSettings($alreadyEncrypted->id)['trello_api_key'];
        $infakt = Integration::create(['account_id' => $this->account->id, 'provider' => 'infakt', 'api_key' => 'k', 'settings' => null]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        $migration = $this->migration();
        $migration->up();
        $firstRun = $this->rawSettings($legacy->id)['trello_api_key'];
        $migration->up();

        $this->assertSame($firstRun, $this->rawSettings($legacy->id)['trello_api_key'], 'a second run changes nothing');
        $this->assertSame('legacy-plain-key', Crypt::decryptString($firstRun));
        $this->assertSame('kept', $this->rawSettings($legacy->id)['other']);
        $this->assertSame($encryptedBefore, $this->rawSettings($alreadyEncrypted->id)['trello_api_key']);
        $this->assertNull($infakt->fresh()->settings);
        $this->assertSame('legacy-plain-key', $legacy->fresh()->settings['trello_api_key']);
        $this->assertSame([], $logged);

        $migration->down();
        $migration->down();

        $this->assertSame('legacy-plain-key', $this->rawSettings($legacy->id)['trello_api_key']);
        $this->assertSame('new-key', $this->rawSettings($alreadyEncrypted->id)['trello_api_key']);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function trello(array $settings, ?Account $account = null): Integration
    {
        return Integration::create([
            'account_id' => ($account ?? $this->account)->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'token',
            'settings' => $settings,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawSettings(int $id): array
    {
        return json_decode((string) DB::table('integrations')->where('id', $id)->value('settings'), true);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function writeRawSettings(int $id, array $settings): void
    {
        DB::table('integrations')->where('id', $id)->update(['settings' => json_encode($settings)]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_01_130100_encrypt_secret_integration_settings.php');
    }
}

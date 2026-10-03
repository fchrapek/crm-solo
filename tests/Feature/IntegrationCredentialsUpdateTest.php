<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The edit form starts every credential field blank, so a blank field on save
 * means "keep what is stored", for every provider and every credential.
 */
final class IntegrationCredentialsUpdateTest extends TestCase
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

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function blankPayloads(): array
    {
        $payloads = [];
        foreach (array_keys(Integration::PROVIDERS) as $provider) {
            $payloads["{$provider}, fields omitted"] = [$provider, ['is_enabled' => false]];
            $payloads["{$provider}, fields empty"] = [$provider, ['is_enabled' => false, 'api_key' => '', 'trello_api_key' => '']];
            $payloads["{$provider}, fields whitespace"] = [$provider, ['is_enabled' => false, 'api_key' => '   ', 'trello_api_key' => '   ']];
            $payloads["{$provider}, blank key inside settings"] = [$provider, ['is_enabled' => false, 'settings' => ['trello_api_key' => '', 'api_key' => '']]];
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('blankPayloads')]
    public function test_saving_with_blank_credentials_keeps_the_stored_ones(string $provider, array $payload): void
    {
        $integration = $this->configured($provider);

        $this->actingAs($this->user)
            ->put("/integrations/{$provider}", $payload)
            ->assertRedirect("/integrations/{$provider}");

        $integration->refresh();
        $this->assertFalse($integration->is_enabled, 'the rest of the form still saves');
        $this->assertSame("{$provider}-stored-token", $integration->api_key);
        if (in_array('trello_api_key', Integration::secretFields($provider), true)) {
            $this->assertSame('stored-app-key', $integration->settings['trello_api_key']);
        }
        $this->assertSame('kept', $integration->settings['other']);
        $this->assertTrue($integration->isConfigured());
    }

    public function test_filled_credentials_replace_the_stored_ones(): void
    {
        $integration = $this->configured('trello');

        $this->actingAs($this->user)
            ->put('/integrations/trello', ['is_enabled' => true, 'api_key' => 'new-token', 'trello_api_key' => 'new-app-key'])
            ->assertRedirect();

        $integration->refresh();
        $this->assertSame('new-token', $integration->api_key);
        $this->assertSame('new-app-key', $integration->settings['trello_api_key']);
        $this->assertSame('kept', $integration->settings['other']);
    }

    public function test_a_first_save_creates_the_integration_with_its_credentials(): void
    {
        $this->actingAs($this->user)
            ->put('/integrations/trello', ['is_enabled' => true, 'api_key' => 'token', 'trello_api_key' => 'app-key'])
            ->assertRedirect();

        $integration = Integration::where('provider', 'trello')->sole();
        $this->assertSame($this->account->id, $integration->account_id);
        $this->assertTrue($integration->isConfigured());
    }

    public function test_a_credential_of_another_provider_is_not_stored(): void
    {
        $integration = $this->configured('infakt');

        $this->actingAs($this->user)
            ->put('/integrations/infakt', ['is_enabled' => true, 'trello_api_key' => 'stray'])
            ->assertRedirect();

        $this->assertArrayNotHasKey('trello_api_key', $integration->fresh()->settings);
    }

    private function configured(string $provider): Integration
    {
        $settings = ['other' => 'kept'];
        if (in_array('trello_api_key', Integration::secretFields($provider), true)) {
            $settings['trello_api_key'] = 'stored-app-key';
        }

        return Integration::create([
            'account_id' => $this->account->id,
            'provider' => $provider,
            'is_enabled' => true,
            'api_key' => "{$provider}-stored-token",
            'settings' => $settings,
        ]);
    }
}

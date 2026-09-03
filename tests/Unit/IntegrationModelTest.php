<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Integration;
use PHPUnit\Framework\TestCase;

final class IntegrationModelTest extends TestCase
{
    public function test_infakt_is_not_oauth_provider(): void
    {
        $integration = new Integration(['provider' => 'infakt']);

        $this->assertFalse($integration->isOAuthProvider());
    }

    public function test_has_valid_oauth_tokens(): void
    {
        $integration = new Integration([
            'provider' => 'infakt',
            'settings' => [
                'access_token' => 'token',
                'refresh_token' => 'refresh',
            ],
        ]);

        $this->assertTrue($integration->hasValidOAuthTokens());
    }

    public function test_missing_oauth_tokens(): void
    {
        $integration = new Integration([
            'provider' => 'infakt',
            'settings' => [],
        ]);

        $this->assertFalse($integration->hasValidOAuthTokens());
    }

    public function test_not_configured_without_api_key(): void
    {
        $integration = new Integration([
            'provider' => 'infakt',
        ]);

        $this->assertFalse($integration->isConfigured());
    }

    public function test_gmail_is_no_longer_a_provider(): void
    {
        $integration = new Integration(['provider' => 'gmail']);

        $this->assertNull($integration->getProviderConfig());
        $this->assertArrayNotHasKey('gmail', Integration::PROVIDERS);
    }

    public function test_unknown_provider_config_returns_null(): void
    {
        $integration = new Integration(['provider' => 'nonexistent']);

        $this->assertNull($integration->getProviderConfig());
    }
}

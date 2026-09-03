<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Integration;
use PHPUnit\Framework\TestCase;

final class ClockifyIntegrationTest extends TestCase
{
    public function test_clockify_provider_config(): void
    {
        $integration = new Integration(['provider' => 'clockify']);
        $config = $integration->getProviderConfig();

        $this->assertSame('Clockify', $config['name']);
        $this->assertSame('api_key', $config['auth_type']);
        $this->assertContains('time_entries', $config['features']);
    }

    public function test_clockify_is_not_oauth_provider(): void
    {
        $integration = new Integration(['provider' => 'clockify']);

        $this->assertFalse($integration->isOAuthProvider());
    }
}

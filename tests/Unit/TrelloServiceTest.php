<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Integration;
use PHPUnit\Framework\TestCase;

final class TrelloServiceTest extends TestCase
{
    public function test_trello_provider_config(): void
    {
        $integration = new Integration(['provider' => 'trello']);
        $config = $integration->getProviderConfig();

        $this->assertSame('Trello', $config['name']);
        $this->assertSame('api_key', $config['auth_type']);
        $this->assertContains('projects', $config['features']);
        $this->assertContains('tasks', $config['features']);
    }

    public function test_trello_is_not_oauth_provider(): void
    {
        $integration = new Integration(['provider' => 'trello']);

        $this->assertFalse($integration->isOAuthProvider());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A page on a hostname that resolves to this machine must not reach the app,
 * whatever the environment, while every host the documented setups use does.
 */
final class HostAllowlistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://crm-solo.test', 'app.allowed_hosts' => ['crm.lan']]);
    }

    public function test_the_app_url_host_loopback_names_and_configured_hosts_are_served(): void
    {
        foreach ([
            'https://crm-solo.test/login',
            'http://127.0.0.1:8101/login',
            'http://localhost:8101/login',
            'http://[::1]:8101/login',
            'http://CRM.LAN/login',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_any_other_host_is_refused_before_a_controller(): void
    {
        $this->get('http://rebind.example:8101/login')
            ->assertStatus(400)
            ->assertSee('Unknown host.');

        $this->get('http://crm-solo.test.example/up')->assertStatus(400);
    }

    public function test_a_forwarded_host_cannot_launder_an_unknown_host(): void
    {
        $this->withHeaders(['X-Forwarded-Host' => 'crm-solo.test'])
            ->get('http://rebind.example:8101/login')
            ->assertStatus(400);

        $this->withHeaders(['X-Forwarded-Host' => 'rebind.example'])
            ->get('http://127.0.0.1:8101/login')
            ->assertStatus(400);
    }

    public function test_the_check_holds_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->get('http://rebind.example/login')->assertStatus(400);
    }
}

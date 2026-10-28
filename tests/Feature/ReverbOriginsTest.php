<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ReverbOriginsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $this->setEnv($key, $value === false ? null : $value);
        }

        parent::tearDown();
    }

    public function test_sockets_are_accepted_only_from_the_app_url_host_when_no_list_is_set(): void
    {
        $this->overrideEnv('APP_URL', 'https://app.example.com');
        $this->overrideEnv('REVERB_ALLOWED_ORIGINS', null);
        $this->assertSame(['app.example.com'], $this->freshOrigins());

        $this->overrideEnv('REVERB_ALLOWED_ORIGINS', '');
        $this->assertSame(['app.example.com'], $this->freshOrigins());
    }

    public function test_an_explicit_list_replaces_the_default(): void
    {
        $this->overrideEnv('APP_URL', 'https://app.example.com');
        $this->overrideEnv('REVERB_ALLOWED_ORIGINS', 'crm-solo.test, 127.0.0.1');

        $this->assertSame(['crm-solo.test', '127.0.0.1'], $this->freshOrigins());
    }

    /** @return list<string> */
    private function freshOrigins(): array
    {
        $config = require config_path('reverb.php');

        return $config['apps']['apps'][0]['allowed_origins'];
    }

    private function overrideEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->saved)) {
            $this->saved[$key] = getenv($key);
        }

        $this->setEnv($key, $value);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}

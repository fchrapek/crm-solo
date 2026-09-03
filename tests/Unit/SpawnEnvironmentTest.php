<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Concerns\SpawnEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpawnEnvironmentTest extends TestCase
{
    /**
     * Every env var any test in this class mutates. Snapshotted in setUp()
     * and restored in tearDown() — putenv() changes leak across the whole
     * PHPUnit process otherwise (BackupGlobals does NOT cover putenv), which
     * crippled PATH for later process-spawning tests (BackupCommandsTest).
     */
    private const MUTATED_ENV_KEYS = [
        'PATH', 'HOME', 'USER', 'SHELL', 'TERM',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'REDIS_PASSWORD', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY',
        'MAIL_PASSWORD', 'REVERB_APP_SECRET',
    ];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::MUTATED_ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function prefix_starts_with_env_i_so_parent_environment_is_cleared(): void
    {
        $prefix = SpawnEnvironment::allowlistedPrefix();

        $this->assertStringStartsWith('env -i ', $prefix);
    }

    #[Test]
    public function db_credentials_from_parent_environment_are_not_forwarded(): void
    {
        // Simulate the exact failure mode: PHP server has CRM Solo's DB env
        // exported from .env. Without the strip, a child shell inherits them
        // and an agent's `php artisan migrate` runs against this DB.
        putenv('DB_HOST=127.0.0.1');
        putenv('DB_PORT=33061');
        putenv('DB_DATABASE=crm_solo');
        putenv('DB_USERNAME=crm_solo');
        putenv('DB_PASSWORD=secret');
        putenv('REDIS_PASSWORD=alsosecret');
        putenv('OPENAI_API_KEY=sk-test-token');
        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        putenv('MAIL_PASSWORD=mailsecret');
        putenv('REVERB_APP_SECRET=reverbsecret');

        $prefix = SpawnEnvironment::allowlistedPrefix();

        // None of the dangerous vars may leak — assertion per individual
        // var so a regression in any one of them fails with a clear message.
        $this->assertStringNotContainsString('DB_HOST=', $prefix);
        $this->assertStringNotContainsString('DB_PORT=', $prefix);
        $this->assertStringNotContainsString('DB_DATABASE=', $prefix);
        $this->assertStringNotContainsString('DB_USERNAME=', $prefix);
        $this->assertStringNotContainsString('DB_PASSWORD=', $prefix);
        $this->assertStringNotContainsString('REDIS_PASSWORD=', $prefix);
        $this->assertStringNotContainsString('OPENAI_API_KEY=', $prefix);
        $this->assertStringNotContainsString('ANTHROPIC_API_KEY=', $prefix);
        $this->assertStringNotContainsString('MAIL_PASSWORD=', $prefix);
        $this->assertStringNotContainsString('REVERB_APP_SECRET=', $prefix);

        // And the actual values must not appear either (catches accidental
        // forwarding under a renamed key).
        $this->assertStringNotContainsString('crm_solo', $prefix);
        $this->assertStringNotContainsString('33061', $prefix);
        $this->assertStringNotContainsString('sk-ant-test', $prefix);
        $this->assertStringNotContainsString('sk-test-token', $prefix);
        // Cleanup happens in tearDown() so it runs even when an assertion fails.
    }

    #[Test]
    public function shell_essentials_are_preserved_so_the_child_bash_is_usable(): void
    {
        putenv('PATH=/usr/bin:/bin');
        putenv('HOME=/Users/test');
        putenv('USER=test');
        putenv('SHELL=/bin/bash');
        putenv('TERM=xterm-256color');

        $prefix = SpawnEnvironment::allowlistedPrefix();

        $this->assertStringContainsString('PATH=', $prefix);
        $this->assertStringContainsString('HOME=', $prefix);
        $this->assertStringContainsString('USER=', $prefix);
        $this->assertStringContainsString('SHELL=', $prefix);
        $this->assertStringContainsString('TERM=', $prefix);
    }

    #[Test]
    public function explicit_extras_are_forwarded_and_shell_escaped(): void
    {
        $prefix = SpawnEnvironment::allowlistedPrefix([
            'CRM_SESSION_TOKEN' => 'abc-123-xyz',
            'CUSTOM_VAR' => "value with 'quotes'",
        ]);

        $this->assertStringContainsString('CRM_SESSION_TOKEN=', $prefix);
        $this->assertStringContainsString('abc-123-xyz', $prefix);
        $this->assertStringContainsString('CUSTOM_VAR=', $prefix);
        // escapeshellarg wraps in single quotes; quotes inside the value
        // get '\'' splice-quoting. Either way the literal value substring
        // is preserved.
        $this->assertStringContainsString('value with', $prefix);
    }

    #[Test]
    public function empty_values_are_dropped(): void
    {
        $prefix = SpawnEnvironment::allowlistedPrefix(['EMPTY_VAR' => '']);

        $this->assertStringNotContainsString('EMPTY_VAR=', $prefix);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class GenerateReverbKeysTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/reverb-keys-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->directory);
        $this->app->useEnvironmentPath($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_blank_and_published_values_are_replaced_with_random_ones(): void
    {
        $this->writeEnv("APP_NAME=x\nREVERB_APP_KEY=laravel-reverb-key\nREVERB_APP_SECRET=\nVITE_REVERB_APP_KEY=\"\${REVERB_APP_KEY}\"\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $env = $this->readEnv();
        $this->assertMatchesRegularExpression('/^REVERB_APP_KEY=[a-z0-9]{32}$/m', $env);
        $this->assertMatchesRegularExpression('/^REVERB_APP_SECRET=[a-z0-9]{32}$/m', $env);
        $this->assertStringNotContainsString('laravel-reverb-key', $env);
        $this->assertStringContainsString('VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"', $env);
        $this->assertStringContainsString("APP_NAME=x\n", $env);
    }

    public function test_values_already_chosen_are_kept_unless_forced(): void
    {
        $this->writeEnv("REVERB_APP_KEY=mine\nREVERB_APP_SECRET=also-mine\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();
        $this->assertSame("REVERB_APP_KEY=mine\nREVERB_APP_SECRET=also-mine\n", $this->readEnv());

        $this->artisan('setup:reverb-keys', ['--force' => true])->assertSuccessful();
        $this->assertStringNotContainsString('mine', $this->readEnv());
    }

    public function test_missing_lines_are_appended(): void
    {
        $this->writeEnv("APP_NAME=x\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $this->assertMatchesRegularExpression('/^REVERB_APP_KEY=[a-z0-9]{32}$/m', $this->readEnv());
        $this->assertMatchesRegularExpression('/^REVERB_APP_SECRET=[a-z0-9]{32}$/m', $this->readEnv());
    }

    public function test_an_inline_comment_or_quotes_around_a_published_value_still_count_as_published(): void
    {
        $this->writeEnv("# reverb\nREVERB_APP_KEY=\"laravel-reverb-key\" # quoted\nREVERB_APP_SECRET=secret # old default\nOTHER=1 # keep\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $env = $this->readEnv();
        $this->assertMatchesRegularExpression('/^REVERB_APP_KEY=[a-z0-9]{32}\n/m', $env);
        $this->assertMatchesRegularExpression('/^REVERB_APP_SECRET=[a-z0-9]{32}\n/m', $env);
        $this->assertStringStartsWith("# reverb\n", $env);
        $this->assertStringEndsWith("OTHER=1 # keep\n", $env);
    }

    public function test_a_quoted_chosen_value_with_a_comment_is_kept(): void
    {
        $contents = "REVERB_APP_KEY='my-key' # mine\nREVERB_APP_SECRET=\"my secret\"\n";
        $this->writeEnv($contents);

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $this->assertSame($contents, $this->readEnv());
    }

    public function test_crlf_files_keep_their_line_endings_and_a_blank_value_is_blank(): void
    {
        $this->writeEnv("APP_NAME=x\r\nREVERB_APP_KEY=\r\nREVERB_APP_SECRET=chosen\r\n# tail\r\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $env = $this->readEnv();
        $this->assertMatchesRegularExpression('/^APP_NAME=x\r\nREVERB_APP_KEY=[a-z0-9]{32}\r\nREVERB_APP_SECRET=chosen\r\n# tail\r\n$/', $env);
    }

    public function test_crlf_files_get_appended_lines_in_crlf(): void
    {
        $this->writeEnv("APP_NAME=x\r\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $this->assertMatchesRegularExpression('/^APP_NAME=x\r\nREVERB_APP_KEY=[a-z0-9]{32}\r\nREVERB_APP_SECRET=[a-z0-9]{32}\r\n$/', $this->readEnv());
    }

    public function test_a_key_that_appears_twice_is_judged_by_the_last_and_every_copy_is_rewritten(): void
    {
        $this->writeEnv("REVERB_APP_KEY=chosen\nREVERB_APP_SECRET=s\nREVERB_APP_KEY=laravel-reverb-key\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $env = $this->readEnv();
        $this->assertSame(2, preg_match_all('/^REVERB_APP_KEY=([a-z0-9]{32})$/m', $env, $m));
        $this->assertSame($m[1][0], $m[1][1]);
        $this->assertStringContainsString("REVERB_APP_SECRET=s\n", $env);
    }

    public function test_an_interpolated_value_is_judged_by_what_it_resolves_to(): void
    {
        $this->writeEnv("BASE=secret\nREVERB_APP_KEY=\"\${OWN_KEY}\"\nOWN_KEY=mine\nREVERB_APP_SECRET=\"\${BASE}\"\n");

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $env = $this->readEnv();
        $this->assertMatchesRegularExpression('/^REVERB_APP_SECRET=[a-z0-9]{32}$/m', $env, 'Resolves to the published secret, so it is replaced with a literal.');
        $this->assertStringContainsString("BASE=secret\n", $env);
    }

    public function test_an_interpolated_chosen_value_is_kept(): void
    {
        $contents = "MINE=chosen-secret\nREVERB_APP_KEY=key\nREVERB_APP_SECRET=\"\${MINE}\"\n";
        $this->writeEnv($contents);

        $this->artisan('setup:reverb-keys')->assertSuccessful();

        $this->assertSame($contents, $this->readEnv());
    }

    public function test_a_missing_env_file_fails_and_creates_nothing(): void
    {
        $this->artisan('setup:reverb-keys')->assertFailed();

        $this->assertFileDoesNotExist($this->directory.'/.env');
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->directory.'/.env', $contents);
    }

    private function readEnv(): string
    {
        return (string) file_get_contents($this->directory.'/.env');
    }
}

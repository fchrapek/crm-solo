<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * integrations:probe checks a server's integration credentials with GET
 * requests only, runs no write statement, and never prints a secret.
 */
final class ProbeIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->account = Account::create(['name' => 'Acc']);
        Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'trello-token',
            'settings' => ['trello_api_key' => 'trello-key'],
        ]);
        Integration::create(['account_id' => $this->account->id, 'provider' => 'infakt', 'is_enabled' => true, 'api_key' => 'infakt-key']);

        config([
            'services.kiwwwi.leads.username' => 'probe',
            'services.kiwwwi.leads.app_password' => 'app-password',
            'services.kiwwwi.leads.endpoints.pl.base_url' => 'https://leads.example.test',
            'services.kiwwwi.leads.endpoints.en.base_url' => 'https://en.leads.example.test',
        ]);

        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message.' '.json_encode($e->context));
    }

    public function test_a_healthy_server_passes_with_get_requests_only_and_no_write(): void
    {
        $this->fakeHealthyApis();

        $this->probe(['--account' => $this->account->id])
            ->expectsOutputToContain('2 open boards')
            ->expectsOutputToContain('clients.json answered')
            ->expectsOutputToContain('1 submissions in the last day')
            ->assertSuccessful();

        Http::assertSentCount(6);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $this->assertSame([], $this->writes);
    }

    public function test_a_refused_credential_fails_but_the_other_systems_are_still_probed(): void
    {
        Http::fake([
            'api.trello.com/*' => Http::response(['message' => 'invalid token'], 401),
            'api.infakt.pl/*' => Http::response(['entities' => []]),
            '*leads.example.test/*' => Http::response(['site' => 1, 'submissions' => []]),
        ]);

        $this->probe(['--account' => $this->account->id])
            ->expectsOutputToContain('members/me refused')
            ->expectsOutputToContain('clients.json answered')
            ->assertFailed();

        Http::assertSentCount(4);
        $this->assertSame([], $this->writes);
    }

    public function test_a_trello_error_after_connecting_is_caught_and_the_rest_continues(): void
    {
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response('boom', 500),
            'api.trello.com/1/members/me*' => Http::response(['id' => 'me']),
            'api.infakt.pl/*' => Http::response(['entities' => []]),
            '*leads.example.test/*' => Http::response(['site' => 1, 'submissions' => []]),
        ]);

        $this->probe(['--account' => $this->account->id])
            ->expectsOutputToContain('clients.json answered')
            ->assertFailed();

        $this->assertSame([], $this->writes);
    }

    public function test_an_unreadable_credential_is_reported_and_the_rest_continues(): void
    {
        $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::table('integrations')->where('provider', 'trello')->update(['api_key' => $foreign->encryptString('x')]);
        Http::fake([
            'api.infakt.pl/*' => Http::response(['entities' => []]),
            '*leads.example.test/*' => Http::response(['site' => 1, 'submissions' => []]),
        ]);

        $this->probe(['--account' => $this->account->id])
            ->expectsOutputToContain('wrong APP_KEY')
            ->expectsOutputToContain('clients.json answered')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'trello.com'));
        $this->assertSame([], $this->writes);
    }

    public function test_an_unreachable_lead_endpoint_fails_without_moving_the_cursor(): void
    {
        Http::fake([
            'api.trello.com/*' => Http::response([]),
            'api.infakt.pl/*' => Http::response(['entities' => []]),
            'https://leads.example.test/*' => fn () => throw new ConnectionException('cURL error 7: could not connect'),
            'https://en.leads.example.test/*' => Http::response('Service Unavailable', 503),
        ]);

        $this->listenForWrites();
        $exit = Artisan::call('integrations:probe', ['--account' => $this->account->id, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unreachable', $output);
        $this->assertStringContainsString('HTTP 503', $output);

        $this->assertSame([], $this->writes);
    }

    public function test_a_key_echoed_in_a_client_error_never_reaches_the_output_or_the_log(): void
    {
        $leaky = "infakt-secret-key\nX-Injected: 1";
        Integration::query()->where('provider', 'infakt')->first()->update(['api_key' => $leaky]);
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([]),
            'api.trello.com/*' => Http::response(['id' => 'me']),
            'api.infakt.pl/*' => fn () => throw new ConnectionException("Header value {$leaky} is invalid"),
            '*leads.example.test/*' => Http::response(['site' => 1, 'submissions' => []]),
        ]);

        $this->probe(['--account' => $this->account->id, '--json' => true])
            ->doesntExpectOutputToContain('infakt-secret-key')
            ->assertFailed();

        $this->assertStringNotContainsString('infakt-secret-key', implode("\n", $this->logged));
    }

    public function test_a_whitespace_padded_key_trimmed_by_the_client_is_still_redacted(): void
    {
        Integration::query()->where('provider', 'infakt')->first()->update(['api_key' => '  infakt-padded-key  ']);
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([]),
            'api.trello.com/*' => Http::response(['id' => 'me']),
            'api.infakt.pl/*' => fn () => throw new ConnectionException('Header value infakt-padded-key is invalid'),
            'https://*leads.example.test/*' => Http::response(['site' => 1, 'submissions' => []]),
        ]);

        $this->probe(['--account' => $this->account->id])->assertFailed();

        $this->assertStringNotContainsString('infakt-padded-key', implode("\n", $this->logged));
    }

    public function test_a_lead_password_near_the_length_cutoff_leaves_no_prefix_behind(): void
    {
        config(['services.kiwwwi.leads.app_password' => 'kiwwwi-password-abcdef', 'services.kiwwwi.leads.endpoints.en.base_url' => null]);
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([]),
            'api.trello.com/*' => Http::response(['id' => 'me']),
            'api.infakt.pl/*' => Http::response(['entities' => []]),
            'https://leads.example.test/*' => fn () => throw new ConnectionException(str_repeat('x', 940).' kiwwwi-password-abcdef'),
        ]);

        $this->listenForWrites();
        Artisan::call('integrations:probe', ['--account' => $this->account->id, '--json' => true]);

        $this->assertStringNotContainsString('kiwwwi-pass', Artisan::output());
    }

    public function test_an_invalid_account_is_refused_before_anything_is_probed(): void
    {
        Http::fake();

        foreach (['0', 'abc', '999', '', null] as $account) {
            $this->probe(['--account' => $account])->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_nothing_to_check_is_a_failure_not_a_vacuous_pass(): void
    {
        Integration::query()->update(['is_enabled' => false]);
        config(['services.kiwwwi.leads.app_password' => '']);
        Http::fake();

        $this->probe([])
            ->expectsOutputToContain('Nothing was checked')
            ->assertFailed();

        Http::assertNothingSent();
    }

    private function probe(array $options)
    {
        $this->listenForWrites();

        return $this->artisan('integrations:probe', $options);
    }

    /** Records every statement that could change the database. */
    private function listenForWrites(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|create|drop|alter)\b/i', $query->sql) === 1) {
                $this->writes[] = $query->sql;
            }
        });
        Event::listen([KeyWritten::class, KeyForgotten::class], fn (object $e) => $this->writes[] = 'cache: '.$e->key);
    }

    private function fakeHealthyApis(): void
    {
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([['id' => 'b1', 'name' => 'One'], ['id' => 'b2', 'name' => 'Two']]),
            'api.trello.com/1/members/me*' => Http::response(['id' => 'me']),
            'api.trello.com/1/boards/b1/lists*' => Http::response([['id' => 'l1', 'name' => 'To-Do']]),
            'api.infakt.pl/*' => Http::response(['entities' => []]),
            'https://leads.example.test/*' => Http::response(['site' => 1, 'submissions' => [['id' => 1]]]),
            'https://en.leads.example.test/*' => Http::response(['site' => 2, 'submissions' => []]),
        ]);
    }
}

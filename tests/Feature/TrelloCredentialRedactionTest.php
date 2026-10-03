<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncTrelloProjectsJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use App\Services\Integrations\Trello\TrelloRequestFailed;
use App\Services\TaskSources\TaskSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Trello key and token travel in the Authorization header, and no error
 * that leaves the Trello client carries them, whatever the transport put in it.
 */
final class TrelloCredentialRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    private const TOKEN = 'ATTAfixturetoken0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKL';

    private User $user;

    private Integration $integration;

    private Project $project;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create([
            'account_id' => $account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Client']);
        $this->project = $client->ensureGeneralProject();
        $this->integration = Integration::create([
            'account_id' => $account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => self::TOKEN,
            'settings' => ['trello_api_key' => self::KEY],
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    public function test_credentials_go_in_the_authorization_header_not_the_url(): void
    {
        Http::fake(['api.trello.com/*' => Http::response([])]);

        $this->actingAs($this->user)
            ->getJson("/projects/{$this->project->id}/available-trello-boards")
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            return $request->header('Authorization')[0] === 'OAuth oauth_consumer_key="'.self::KEY.'", oauth_token="'.self::TOKEN.'"'
                && ! str_contains($request->url(), self::KEY)
                && ! str_contains($request->url(), self::TOKEN);
        });
    }

    public function test_a_connection_error_is_redacted_in_the_json_response(): void
    {
        $this->fakeConnectionErrorWithCredentialsInTheUrl();

        $response = $this->actingAs($this->user)
            ->getJson("/projects/{$this->project->id}/available-trello-boards")
            ->assertStatus(502);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('cURL error 28', $message);
        $this->assertStringContainsString('key=[redacted]', $message);
        $this->assertSecretFree($message);
    }

    public function test_a_connection_error_is_redacted_in_the_flash_error(): void
    {
        $this->fakeConnectionErrorWithCredentialsInTheUrl();

        $this->actingAs($this->user)
            ->post("/projects/{$this->project->id}/connect-trello", ['mode' => 'link', 'trello_board_id' => 'board1'])
            ->assertSessionHasErrors('trello');

        $this->assertSecretFree((string) session('errors')->first('trello'));
    }

    public function test_a_connection_error_is_redacted_in_the_job_failure_and_its_log(): void
    {
        $this->fakeConnectionErrorWithCredentialsInTheUrl();
        $job = new SyncTrelloProjectsJob($this->integration);

        $thrown = null;
        try {
            $job->handle(app(TaskSourceRegistry::class));
        } catch (TrelloRequestFailed $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the sync must fail with the client error');
        $this->assertStringContainsString('cURL error 28', $thrown->getMessage());
        $this->assertSecretFree((string) $thrown);
        $this->assertNull($thrown->getPrevious());
        $job->failed($thrown);

        $this->assertNotEmpty($this->logged);
        $this->assertSecretFree(implode("\n", $this->logged));
        $this->assertSecretFree((string) $this->integration->fresh()->last_sync_error);
    }

    public function test_an_error_response_echoing_the_credentials_is_redacted(): void
    {
        Http::fake(['api.trello.com/*' => Http::response('invalid key '.self::KEY.' for token '.self::TOKEN, 401)]);

        $response = $this->actingAs($this->user)
            ->getJson("/projects/{$this->project->id}/available-trello-boards")
            ->assertStatus(502);

        $this->assertStringContainsString('HTTP 401', (string) $response->json('message'));
        $this->assertSecretFree((string) $response->json('message'));
    }

    public function test_a_token_after_long_padding_is_redacted_before_the_body_is_shortened(): void
    {
        $this->assertSame(64, mb_strlen(self::TOKEN));
        $this->fakeBoardsError(str_repeat('x', 480).self::TOKEN);

        $message = $this->boardsErrorMessage();

        $this->assertStringContainsString('HTTP 401', $message);
        $this->assertSecretFree($message);
        $this->assertStringNotContainsString(mb_substr(self::TOKEN, 0, 20), $message);
    }

    public function test_a_token_written_as_json_unicode_escapes_is_redacted(): void
    {
        $escaped = implode('', array_map(fn (string $char): string => sprintf('\\u%04x', ord($char)), mb_str_split(self::TOKEN)));
        $this->fakeBoardsError('{"message":"invalid token '.$escaped.'"}');

        $message = $this->boardsErrorMessage();

        $this->assertStringContainsString('invalid token [redacted]', $message);
        $this->assertSecretFree($message);
        $this->assertStringNotContainsString($escaped, $message);
    }

    public function test_a_url_encoded_key_is_redacted(): void
    {
        $encoded = implode('', array_map(fn (string $char): string => '%'.bin2hex($char), mb_str_split(self::KEY)));
        $this->fakeBoardsError('bad request for consumer '.$encoded);

        $message = $this->boardsErrorMessage();

        $this->assertSecretFree($message);
        $this->assertStringNotContainsString($encoded, $message);
    }

    public function test_a_body_that_cannot_be_made_safe_is_not_shown_at_all(): void
    {
        // A cut-off copy of the token is not the whole secret, so replacement alone would miss it.
        $this->fakeBoardsError('token prefix '.mb_substr(self::TOKEN, 0, 30));

        $this->assertSame('Failed to fetch Trello boards: HTTP 401', $this->boardsErrorMessage());
    }

    private function fakeBoardsError(string $body): void
    {
        Http::fake(['api.trello.com/*' => Http::response($body, 401)]);
    }

    private function boardsErrorMessage(): string
    {
        return (string) $this->actingAs($this->user)
            ->getJson("/projects/{$this->project->id}/available-trello-boards")
            ->assertStatus(502)
            ->json('message');
    }

    private function fakeConnectionErrorWithCredentialsInTheUrl(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException(
                'cURL error 28: Operation timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '
                .'https://api.trello.com/1/members/me/boards?key='.self::KEY.'&token='.self::TOKEN.'&filter=open'
            );
        });
    }

    private function assertSecretFree(string $text): void
    {
        $this->assertStringNotContainsString(self::KEY, $text);
        $this->assertStringNotContainsString(self::TOKEN, $text);
    }
}

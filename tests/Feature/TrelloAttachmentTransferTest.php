<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Services\Agent\CardAttachmentPuller;
use App\Services\Integrations\Trello\TrelloAttachmentTooLarge;
use App\Services\Integrations\Trello\TrelloService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Attachment downloads over a real HTTP transfer (curl through Guzzle, no
 * fake), against `php -S` servers standing in for Trello and a foreign file
 * store: the size guard stops the transfer once the cap is passed instead of
 * after the whole body, the partial file is removed, and a redirect to
 * another origin does not carry the Trello Authorization header.
 */
final class TrelloAttachmentTransferTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<resource> */
    private array $servers = [];

    private string $log;

    private string $trelloOrigin;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = tempnam(sys_get_temp_dir(), 'trello-transfer-');
        $trelloPort = $this->freePort();
        $foreignPort = $this->freePort();
        $this->trelloOrigin = "http://127.0.0.1:{$trelloPort}";
        $foreignOrigin = "http://localhost:{$foreignPort}";

        $this->serve('127.0.0.1', $trelloPort, $foreignOrigin);
        $this->serve('localhost', $foreignPort, $foreignOrigin);

        config(['services.trello.base_url' => $this->trelloOrigin.'/1']);
        $account = Account::factory()->create();
        $this->integration = Integration::create(['account_id' => $account->id, 'provider' => 'trello', 'is_enabled' => true, 'api_key' => 'token-value', 'settings' => ['trello_api_key' => 'key-value']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_the_size_guard_stops_the_transfer_before_the_body_ends(): void
    {
        $to = tempnam(sys_get_temp_dir(), 'trello-download-');
        $started = microtime(true);

        try {
            (new TrelloService($this->integration))->downloadAttachment('c1', 'a1', 'big.png', $to, 10_000, 30);
            $this->fail('The download was not stopped.');
        } catch (TrelloAttachmentTooLarge) {
            // Expected.
        }

        // The server sends 64 KB, then waits 3 s before the rest: an abort at the cap returns well before.
        $this->assertLessThan(2.5, microtime(true) - $started);
        $this->assertLessThan(64 * 1024 * 2, (int) @filesize($to));
        @unlink($to);
    }

    public function test_the_puller_removes_the_partial_file_after_an_abort(): void
    {
        Storage::fake('local');
        config(['services.trello.attachment_max_kb' => 10]);
        $task = $this->task();

        $manifest = app(CardAttachmentPuller::class)->pull($task, new TrelloService($this->integration), [[
            'id' => 'a1', 'name' => 'big.png', 'bytes' => null, 'isUpload' => true,
            'url' => $this->trelloOrigin.'/1/cards/c1/attachments/a1/download/big.png',
        ]], [], CarbonImmutable::now()->addMinutes(4), false);

        $this->assertSame('refused', $manifest[0]['status']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_redirect_to_another_origin_does_not_carry_the_authorization_header(): void
    {
        $to = tempnam(sys_get_temp_dir(), 'trello-download-');

        (new TrelloService($this->integration))->downloadAttachment('c1', 'a2', 'moved.png', $to, 100_000, 30);

        // Only the final response's body lands in the file: the redirect's own body is dropped.
        $this->assertSame(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), file_get_contents($to));
        $lines = file($this->log, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+ \/1\/cards\/c1\/attachments\/a2\/download\/moved\.png auth=OAuth oauth_consumer_key="key-value"/', $lines[0] ?? '');
        $this->assertMatchesRegularExpression('/^localhost:\d+ \/store\/moved\.png auth=none$/', $lines[1] ?? '');
        @unlink($to);
    }

    private function task(): Task
    {
        $project = Project::create(['account_id' => $this->integration->account_id, 'name' => 'Board', 'trello_board_id' => 'b1']);

        return Task::create(['project_id' => $project->id, 'name' => 'Card', 'source' => 'trello', 'trello_card_id' => 'c1']);
    }

    private function serve(string $host, int $port, string $foreignOrigin): void
    {
        $router = base_path('tests/Fixtures/trello-transfer/router.php');
        $server = proc_open(
            [PHP_BINARY, '-d', 'output_buffering=0', '-S', "{$host}:{$port}", $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['TRANSFER_LOG' => $this->log, 'FOREIGN_ORIGIN' => $foreignOrigin, 'PATH' => (string) getenv('PATH')],
        );
        $this->assertIsResource($server);
        $this->servers[] = $server;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen($host, $port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        $this->fail("The test server on {$host}:{$port} did not start.");
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) mb_substr($name, (int) mb_strrpos($name, ':') + 1);
    }
}

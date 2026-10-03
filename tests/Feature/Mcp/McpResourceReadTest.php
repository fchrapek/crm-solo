<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Laravel\Mcp\Server\Contracts\Transport;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * resources/read through the server's own JSON-RPC dispatcher: a record comes
 * back as contents, a malformed id is invalid params, and a missing record or
 * one in another account is resource not found, never an internal error.
 */
final class McpResourceReadTest extends TestCase
{
    use RefreshDatabase;

    private Task $task;

    private Client $client;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private Closure $receive;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Res Co']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $this->task = Task::create(['project_id' => $project->id, 'name' => 'Res task', 'source' => 'manual']);

        $test = $this;
        $transport = new class($test) implements Transport
        {
            public function __construct(private McpResourceReadTest $test) {}

            public function onReceive(Closure $handler): void
            {
                $this->test->listen($handler);
            }

            public function send(string $message, ?string $sessionId = null): void
            {
                $this->test->record($message);
            }

            public function run(): Response|StreamedResponse
            {
                throw new LogicException('Not used.');
            }

            public function sessionId(): ?string
            {
                return 'test-session';
            }

            public function stream(Closure $stream): void
            {
                $stream();
            }
        };

        app()->make(CrmServer::class, ['transport' => $transport])->start();
        ($this->receive)((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1'],
        ]]));
    }

    public function listen(Closure $handler): void
    {
        $this->receive = $handler;
    }

    public function record(string $message): void
    {
        $this->sent[] = json_decode($message, true);
    }

    public function test_a_task_resource_returns_the_record(): void
    {
        $reply = $this->read('crm://tasks/'.$this->task->id);

        $this->assertArrayNotHasKey('error', $reply);
        $this->assertSame('application/json', $reply['result']['contents'][0]['mimeType']);
        $this->assertSame('crm.task/1', json_decode($reply['result']['contents'][0]['text'], true)['schema']);
    }

    public function test_a_malformed_id_is_invalid_params(): void
    {
        $reply = $this->read('crm://tasks/nope');

        $this->assertSame(-32602, $reply['error']['code']);
        $this->assertStringContainsString('numeric id', $reply['error']['message']);
    }

    public function test_a_missing_task_is_resource_not_found(): void
    {
        $reply = $this->read('crm://tasks/999999');

        $this->assertSame(-32002, $reply['error']['code']);
        $this->assertSame('not_found', $reply['error']['data']['error']);
    }

    public function test_another_accounts_records_are_resource_not_found(): void
    {
        $other = Account::factory()->create();
        $otherClient = Client::factory()->create(['account_id' => $other->id]);
        $otherTask = Task::create(['project_id' => Project::create(['account_id' => $other->id, 'client_id' => $otherClient->id, 'name' => 'X'])->id, 'name' => 'Theirs', 'source' => 'manual']);

        $this->assertSame(-32002, $this->read('crm://tasks/'.$otherTask->id)['error']['code']);
        $this->assertSame(-32002, $this->read('crm://clients/'.$otherClient->id)['error']['code']);
        $this->assertSame(-32602, $this->read('crm://clients/abc')['error']['code']);
        $this->assertArrayNotHasKey('error', $this->read('crm://clients/'.$this->client->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $uri): array
    {
        $this->sent = [];
        ($this->receive)((string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/read', 'params' => ['uri' => $uri]]));

        return collect($this->sent)->firstWhere('id', 7) ?? $this->fail('No reply to resources/read: '.json_encode($this->sent));
    }
}

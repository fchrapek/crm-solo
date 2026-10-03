<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\SyncTrelloProjectsJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\TaskSources\TaskSourceRegistry;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Configured rows are seeded on purpose, so a missing guard cannot hide behind "not configured".
 */
final class DemoIntegrationsOffTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $linked;

    private Project $private;

    private Integration $trello;

    private Integration $infakt;

    protected function setUp(): void
    {
        parent::setUp();
        // Every outbound request throws, so a guard that lets one through fails loudly.
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        Queue::fake();

        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true, 'first_name' => 'F', 'last_name' => 'C']);
        $client = Client::factory()->create(['account_id' => $account->id]);
        $this->linked = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Linked', 'trello_board_id' => 'board-1']);
        $this->private = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Private']);
        $this->trello = Integration::create([
            'account_id' => $account->id, 'provider' => 'trello', 'is_enabled' => true,
            'api_key' => 'trello-stored-token', 'settings' => ['trello_api_key' => 'stored-app-key'],
        ]);
        $this->infakt = Integration::create([
            'account_id' => $account->id, 'provider' => 'infakt', 'is_enabled' => true,
            'api_key' => 'infakt-stored-token', 'settings' => [],
        ]);

        config(['app.demo' => true]);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function absentRoutes(): array
    {
        return [
            'integration edit form' => ['get', '/integrations/trello', []],
            'save credentials' => ['put', '/integrations/trello', ['is_enabled' => true, 'api_key' => 'visitor-token', 'trello_api_key' => 'visitor-app-key']],
            'save Infakt credentials' => ['put', '/integrations/infakt', ['is_enabled' => true, 'api_key' => 'visitor-token']],
            'sync Trello' => ['post', '/integrations/trello/sync', []],
            'sync Infakt' => ['post', '/integrations/infakt/sync', []],
            'disconnect' => ['delete', '/integrations/trello', []],
            'connect a project to a board' => ['post', '/projects/{private}/connect-trello', ['trello_board_id' => 'visitor-board']],
            'disconnect a project' => ['post', '/projects/{linked}/disconnect-trello', []],
            'sync a project' => ['post', '/projects/{linked}/sync-trello', []],
            'list mapping' => ['put', '/projects/{linked}/trello-list-mapping', ['mapping' => ['list-1' => 'done']]],
            'available boards' => ['get', '/projects/{private}/available-trello-boards', []],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function agentTransports(): array
    {
        return [
            'MCP over HTTP' => ['post', '/mcp'],
            'MCP stream' => ['get', '/mcp'],
            'MCP head' => ['head', '/mcp'],
            'MCP session end' => ['delete', '/mcp'],
            'verb endpoint' => ['post', '/agent/verb'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('absentRoutes')]
    public function test_integration_routes_are_absent_on_the_demo(string $method, string $uri, array $payload): void
    {
        $uri = str_replace(['{private}', '{linked}'], [(string) $this->private->id, (string) $this->linked->id], $uri);
        $settingsBefore = $this->linked->fresh()->settings;

        $this->actingAs($this->owner)->json($method, $uri, $payload)->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertSame('trello-stored-token', $this->trello->fresh()->api_key);
        $this->assertSame('stored-app-key', $this->trello->fresh()->settings['trello_api_key']);
        $this->assertSame('infakt-stored-token', $this->infakt->fresh()->api_key);
        $this->assertTrue($this->trello->fresh()->is_enabled);
        $this->assertNull($this->private->fresh()->trello_board_id);
        $this->assertSame('board-1', $this->linked->fresh()->trello_board_id);
        $this->assertEquals($settingsBefore, $this->linked->fresh()->settings, 'the list mapping is untouched');
    }

    public function test_the_integrations_page_says_they_are_not_part_of_the_demo_and_lists_nothing(): void
    {
        $this->actingAs($this->owner)->get('/integrations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('integrations/index')
                ->where('demo', true)
                ->where('integrations', []));
    }

    public function test_a_partial_reload_of_the_integrations_page_lists_nothing_on_the_demo(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'integrations/index',
                'X-Inertia-Partial-Data' => 'integrations',
            ])
            ->get('/integrations')
            ->assertOk()
            ->assertJsonPath('props.integrations', []);
    }

    public function test_the_client_page_offers_no_trello_connection_on_the_demo_even_with_a_configured_row(): void
    {
        $this->actingAs($this->owner)->get("/clients/{$this->linked->client_id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('trelloEnabled', false)->where('trelloActions', false));

        config(['app.demo' => false]);
        $this->actingAs($this->owner)->get("/clients/{$this->linked->client_id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('trelloEnabled', true)->where('trelloActions', true));
    }

    #[DataProvider('agentTransports')]
    public function test_agent_transports_are_absent_on_the_demo_before_any_token_check(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertNotFound();
        $this->withHeaders(['Authorization' => 'Bearer 1|not-a-token'])->json($method, $uri)->assertNotFound();

        $token = $this->token();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->json($method, $uri, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'verb' => 'today', 'args' => []])
            ->assertNotFound();
    }

    public function test_an_agent_attachment_download_is_absent_on_the_demo(): void
    {
        // The file exists, so the controller would serve it: the 404 can only come from the guard.
        Storage::fake('local');
        $task = Task::create(['project_id' => $this->private->id, 'name' => 'Footer']);
        Storage::disk('local')->put('task-attachments/'.$task->id.'/brief.pdf', '%PDF-1.4 brief');
        $attachment = TaskAttachment::create([
            'task_id' => $task->id, 'file_path' => 'task-attachments/'.$task->id.'/brief.pdf',
            'original_name' => 'brief.pdf', 'mime' => 'application/pdf', 'size' => 14,
        ]);

        $this->get("/agent/attachments/{$attachment->id}")->assertNotFound();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->get("/agent/attachments/{$attachment->id}")
            ->assertNotFound();

        config(['app.demo' => false]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->get("/agent/attachments/{$attachment->id}")
            ->assertOk();
    }

    public function test_the_verb_endpoint_still_answers_outside_the_demo(): void
    {
        config(['app.demo' => false]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->postJson('/agent/verb', ['verb' => 'today', 'args' => []])
            ->assertOk();
    }

    public function test_the_integration_form_still_opens_outside_the_demo(): void
    {
        config(['app.demo' => false]);

        $this->actingAs($this->owner)->get('/integrations/trello')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('integrations/edit'));
    }

    public function test_a_trello_sync_job_already_queued_does_nothing_on_the_demo(): void
    {
        $before = $this->trello->fresh()->last_synced_at;

        (new SyncTrelloProjectsJob($this->trello))->handle(app(TaskSourceRegistry::class));

        Http::assertNothingSent();
        $this->assertEquals($before, $this->trello->fresh()->last_synced_at);
    }

    public function test_no_integration_sync_is_scheduled_on_the_demo(): void
    {
        foreach (['trello:sync', 'kiwwwi:sync-leads', 'infakt:sync-invoices'] as $command) {
            $this->assertFalse($this->scheduled($command), "{$command} is scheduled on the demo");
        }

        config(['app.demo' => false]);
        foreach (['trello:sync', 'kiwwwi:sync-leads', 'infakt:sync-invoices'] as $command) {
            $this->assertTrue($this->scheduled($command), "{$command} is not scheduled outside the demo");
        }
    }

    private function scheduled(string $command): bool
    {
        ScheduleFacade::swap($schedule = new Schedule);
        require base_path('routes/console.php');

        return collect($schedule->events())->contains(fn (Event $event): bool => str_contains((string) $event->command, $command));
    }

    private function token(): string
    {
        $issued = $this->owner->createToken('agent', [AgentAbilities::READ], now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $this->owner->account_id])->save();

        return $issued->plainTextToken;
    }
}

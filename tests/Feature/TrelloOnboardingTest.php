<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\Trello\TrelloOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class TrelloOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Account']);
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'owner' => true,
        ]);
        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'test-token',
            'settings' => [
                'trello_api_key' => 'test-key',
            ],
        ]);
    }

    public function test_onboard_creates_board_lists_and_priority_labels(): void
    {
        Http::fake([
            'api.trello.com/1/boards*' => Http::response([
                'id' => 'board123',
                'name' => 'Acme Corp',
                'url' => 'https://trello.com/b/board123',
            ]),
            'api.trello.com/1/labels*' => Http::sequence()
                ->push(['id' => 'lbl_high', 'name' => 'High', 'color' => 'red'])
                ->push(['id' => 'lbl_med', 'name' => 'Medium', 'color' => 'yellow'])
                ->push(['id' => 'lbl_low', 'name' => 'Low', 'color' => 'green']),
            'api.trello.com/1/lists*' => Http::sequence()
                ->push(['id' => 'list1', 'name' => 'Backlog'])
                ->push(['id' => 'list2', 'name' => 'To-Do'])
                ->push(['id' => 'list3', 'name' => 'Doing'])
                ->push(['id' => 'list4', 'name' => 'Testing'])
                ->push(['id' => 'list5', 'name' => 'Done']),
        ]);

        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Acme Corp',
        ]);

        $service = new TrelloOnboardingService;
        $project = $service->onboard($client, $this->integration);

        $this->assertSame('Acme Corp', $project->name);
        $this->assertSame('board123', $project->trello_board_id);
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame('https://trello.com/b/board123', $project->trello_url);

        $lists = $project->settings['trello_lists'];
        $this->assertCount(5, $lists);
        $this->assertSame('Backlog', $lists[0]['name']);
        $this->assertSame('Done', $lists[4]['name']);

        // List mapping (Trello list ID → canonical CRM lane). Newly-created
        // boards use the canonical names verbatim so the auto-detect lands
        // identity for each list.
        $mapping = $project->settings['trello_list_mapping'];
        $this->assertSame('Backlog', $mapping['list1']);
        $this->assertSame('To-Do', $mapping['list2']);
        $this->assertSame('Doing', $mapping['list3']);
        $this->assertSame('Testing', $mapping['list4']);
        $this->assertSame('Done', $mapping['list5']);

        $priorityLabels = $project->settings['trello_priority_labels'];
        $this->assertSame('lbl_high', $priorityLabels['red']);
        $this->assertSame('lbl_med', $priorityLabels['yellow']);
        $this->assertSame('lbl_low', $priorityLabels['green']);
    }

    public function test_onboard_sends_correct_api_requests(): void
    {
        Http::fake([
            'api.trello.com/1/boards*' => Http::response([
                'id' => 'board123',
                'name' => 'Test Board',
                'url' => 'https://trello.com/b/board123',
            ]),
            'api.trello.com/1/labels*' => Http::sequence()
                ->push(['id' => 'lbl1', 'name' => 'High', 'color' => 'red'])
                ->push(['id' => 'lbl2', 'name' => 'Medium', 'color' => 'yellow'])
                ->push(['id' => 'lbl3', 'name' => 'Low', 'color' => 'green']),
            'api.trello.com/1/lists*' => Http::sequence()
                ->push(['id' => 'list1', 'name' => 'Backlog'])
                ->push(['id' => 'list2', 'name' => 'To-Do'])
                ->push(['id' => 'list3', 'name' => 'Doing'])
                ->push(['id' => 'list4', 'name' => 'Testing'])
                ->push(['id' => 'list5', 'name' => 'Done']),
        ]);

        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Test Client',
        ]);

        $service = new TrelloOnboardingService;
        $service->onboard($client, $this->integration);

        Http::assertSentCount(9); // 1 board + 3 labels + 5 lists
    }

    public function test_onboard_fails_with_unconfigured_integration(): void
    {
        $otherAccount = Account::create(['name' => 'Other Account']);
        $unconfigured = Integration::create([
            'account_id' => $otherAccount->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'settings' => [],
        ]);

        $client = Client::create([
            'account_id' => $otherAccount->id,
            'name' => 'Test Client',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trello integration is not configured.');

        $service = new TrelloOnboardingService;
        $service->onboard($client, $unconfigured);
    }

    public function test_connect_existing_project_attaches_board_without_creating_new_project(): void
    {
        Http::fake([
            'api.trello.com/1/boards*' => Http::response([
                'id' => 'board789',
                'name' => 'Existing Project',
                'url' => 'https://trello.com/b/board789',
            ]),
            'api.trello.com/1/labels*' => Http::sequence()
                ->push(['id' => 'lbl1', 'name' => 'High', 'color' => 'red'])
                ->push(['id' => 'lbl2', 'name' => 'Medium', 'color' => 'yellow'])
                ->push(['id' => 'lbl3', 'name' => 'Low', 'color' => 'green']),
            'api.trello.com/1/lists*' => Http::sequence()
                ->push(['id' => 'list1', 'name' => 'Backlog'])
                ->push(['id' => 'list2', 'name' => 'To-Do'])
                ->push(['id' => 'list3', 'name' => 'Doing'])
                ->push(['id' => 'list4', 'name' => 'Testing'])
                ->push(['id' => 'list5', 'name' => 'Done']),
        ]);

        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Existing Project',
        ]);
        \App\Models\Task::create([
            'project_id' => $project->id,
            'name' => 'Existing manual task',
            'source' => 'manual',
            'is_reviewed' => true,
        ]);

        $projectsBefore = \App\Models\Project::where('client_id', $client->id)->count();

        $service = new TrelloOnboardingService;
        $result = $service->connectExistingProject($project, $this->integration);

        $this->assertSame($project->id, $result->id, 'Same project, not a new one');
        $this->assertSame('board789', $result->trello_board_id);
        $this->assertSame('https://trello.com/b/board789', $result->trello_url);
        // Existing manual task survives
        $this->assertSame(1, $project->tasks()->count());
        $this->assertSame($projectsBefore, \App\Models\Project::where('client_id', $client->id)->count());
    }

    public function test_connect_existing_refuses_already_connected_project(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Already linked',
            'trello_board_id' => 'board000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already connected');

        (new TrelloOnboardingService)->connectExistingProject($project, $this->integration);
    }

    public function test_connect_trello_endpoint_attaches_board(): void
    {
        Http::fake([
            'api.trello.com/1/boards*' => Http::response(['id' => 'b1', 'url' => 'https://trello.com/b/b1']),
            'api.trello.com/1/labels*' => Http::sequence()
                ->push(['id' => 'lbl_r'])
                ->push(['id' => 'lbl_y'])
                ->push(['id' => 'lbl_g']),
            'api.trello.com/1/lists*' => Http::sequence()
                ->push(['id' => 'l1', 'name' => 'Backlog'])
                ->push(['id' => 'l2', 'name' => 'To-Do'])
                ->push(['id' => 'l3', 'name' => 'Doing'])
                ->push(['id' => 'l4', 'name' => 'Testing'])
                ->push(['id' => 'l5', 'name' => 'Done']),
        ]);

        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Maintenance',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/connect-trello")
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('b1', $project->trello_board_id);
    }

    public function test_connect_trello_endpoint_rejects_when_integration_disabled(): void
    {
        $this->integration->update(['is_enabled' => false]);

        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Maintenance',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/connect-trello")
            ->assertStatus(422);

        $project->refresh();
        $this->assertNull($project->trello_board_id);
    }

    public function test_link_existing_trello_board_attaches_to_private_project(): void
    {
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([
                ['id' => 'b_remote', 'name' => 'Remote Work', 'url' => 'https://trello.com/b/b_remote', 'organization' => ['displayName' => 'Solo']],
                ['id' => 'b_other', 'name' => 'Other', 'url' => null],
            ]),
            // Order matters — put the more specific patterns BEFORE the
            // wildcarded board endpoint, otherwise the broad pattern wins.
            'api.trello.com/1/boards/b_remote/lists*' => Http::response([
                ['id' => 'l1', 'name' => 'Inbox', 'pos' => 1000, 'closed' => false],
                ['id' => 'l2', 'name' => 'Done', 'pos' => 2000, 'closed' => false],
            ]),
            'api.trello.com/1/boards/b_remote/cards*' => Http::response([
                ['id' => 'c1', 'name' => 'First task', 'desc' => '', 'idList' => 'l1', 'pos' => 1, 'due' => null, 'dueComplete' => false, 'url' => 'https://trello.com/c/c1', 'labels' => [], 'closed' => false],
            ]),
            'api.trello.com/1/boards/b_remote*' => Http::response([
                'id' => 'b_remote',
                'name' => 'Remote Work',
                'desc' => '',
                'url' => 'https://trello.com/b/b_remote',
            ]),
        ]);

        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'General',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/projects/{$project->id}/connect-trello", [
                'mode' => 'link',
                'trello_board_id' => 'b_remote',
            ]);

        $response->assertRedirect();

        $project->refresh();
        $this->assertSame('b_remote', $project->trello_board_id);
        $this->assertSame('https://trello.com/b/b_remote', $project->trello_url);
        $this->assertSame('Solo', $project->settings['trello_workspace'] ?? null);
        $this->assertCount(2, $project->settings['trello_lists'] ?? []);

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'trello_card_id' => 'c1',
            'name' => 'First task',
        ]);
    }

    public function test_link_existing_board_refuses_when_board_already_linked_elsewhere(): void
    {
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([
                ['id' => 'b_taken', 'name' => 'Taken', 'url' => null],
            ]),
        ]);

        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Already linked',
            'trello_board_id' => 'b_taken',
        ]);
        $target = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'Target',
        ]);

        $response = $this->actingAs($this->user)
            ->from("/clients/{$client->id}/edit")
            ->post("/projects/{$target->id}/connect-trello", [
                'mode' => 'link',
                'trello_board_id' => 'b_taken',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('trello');

        $target->refresh();
        $this->assertNull($target->trello_board_id);
    }

    public function test_link_mode_requires_board_id(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $project = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'General',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/connect-trello", ['mode' => 'link'])
            ->assertStatus(422);
    }

    public function test_available_trello_boards_endpoint_returns_all_boards_with_status(): void
    {
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([
                ['id' => 'b_free', 'name' => 'Personal', 'url' => 'https://trello.com/b/b_free'],
                ['id' => 'b_taken', 'name' => 'Other Client', 'url' => 'https://trello.com/b/b_taken'],
                ['id' => 'b_orphan', 'name' => 'Orphan', 'url' => 'https://trello.com/b/b_orphan'],
                ['id' => 'b_workspace', 'name' => 'Workspace', 'url' => 'https://trello.com/b/b_workspace', 'organization' => ['displayName' => 'Acme']],
            ]),
        ]);

        $clientA = Client::create(['account_id' => $this->account->id, 'name' => 'Client A']);
        \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $clientA->id,
            'name' => 'A board',
            'trello_board_id' => 'b_taken',
        ]);
        \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => null,
            'name' => 'Orphan board',
            'trello_board_id' => 'b_orphan',
        ]);
        $clientB = Client::create(['account_id' => $this->account->id, 'name' => 'Client B']);
        $target = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $clientB->id,
            'name' => 'Private',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/projects/{$target->id}/available-trello-boards");

        $response->assertOk();
        $boards = collect($response->json('boards'));
        $this->assertCount(4, $boards);

        $this->assertSame('available', $boards->firstWhere('id', 'b_free')['linked_status']);
        $this->assertSame('linked', $boards->firstWhere('id', 'b_taken')['linked_status']);
        $this->assertSame('Client A', $boards->firstWhere('id', 'b_taken')['linked_client_name']);
        $this->assertSame('orphan', $boards->firstWhere('id', 'b_orphan')['linked_status']);
        $this->assertSame('Acme', $boards->firstWhere('id', 'b_workspace')['workspace']);
    }

    public function test_link_existing_board_adopts_orphan_project(): void
    {
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([
                ['id' => 'b_orphan', 'name' => 'Synced Board', 'url' => 'https://trello.com/b/b_orphan'],
            ]),
            'api.trello.com/1/boards/b_orphan/lists*' => Http::response([
                ['id' => 'l1', 'name' => 'Inbox', 'pos' => 1000, 'closed' => false],
            ]),
            'api.trello.com/1/boards/b_orphan/cards*' => Http::response([
                ['id' => 'fresh_card', 'name' => 'Fresh', 'desc' => '', 'idList' => 'l1', 'pos' => 1, 'due' => null, 'dueComplete' => false, 'url' => 'https://trello.com/c/fresh_card', 'labels' => [], 'closed' => false],
            ]),
            'api.trello.com/1/boards/b_orphan*' => Http::response([
                'id' => 'b_orphan',
                'name' => 'Synced Board',
                'desc' => '',
                'url' => 'https://trello.com/b/b_orphan',
            ]),
        ]);

        $client = Client::create(['account_id' => $this->account->id, 'name' => 'C']);
        $orphan = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => null,
            'name' => 'Orphan board',
            'trello_board_id' => 'b_orphan',
            'settings' => ['trello_lists' => [['id' => 'old', 'name' => 'Old']]],
        ]);
        $orphanTask = \App\Models\Task::create([
            'project_id' => $orphan->id,
            'name' => 'Pre-existing task',
            'list_name' => 'Inbox',
        ]);

        $target = \App\Models\Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'name' => 'My Private',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/projects/{$target->id}/connect-trello", [
                'mode' => 'link',
                'trello_board_id' => 'b_orphan',
            ]);

        $response->assertRedirect();

        $this->assertNull(\App\Models\Project::find($orphan->id));

        $target->refresh();
        $this->assertSame('b_orphan', $target->trello_board_id);
        // Project name preserved
        $this->assertSame('My Private', $target->name);
        // Pre-existing task migrated
        $this->assertSame($target->id, $orphanTask->fresh()->project_id);
    }
}

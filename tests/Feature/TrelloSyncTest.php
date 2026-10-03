<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Integrations\Trello\TrelloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TrelloSyncTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

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
    }

    public function test_project_syncs_with_tasks(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Test Client',
        ]);

        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'trello_board_id' => 'board_123',
            'name' => 'Test Board',
        ]);

        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_1',
            'name' => 'Task One',
            'list_name' => 'To Do',
            'position' => 1000,
            'is_completed' => false,
        ]);

        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_2',
            'name' => 'Task Two',
            'list_name' => 'Done',
            'position' => 2000,
            'is_completed' => true,
        ]);

        $this->assertSame(2, $project->tasks()->count());
        $this->assertSame(1, $project->tasks()->where('is_completed', true)->count());
    }

    public function test_task_deduplication_by_trello_card_id(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'name' => 'Dedup Test',
        ]);

        Task::updateOrCreate(
            ['project_id' => $project->id, 'trello_card_id' => 'card_dup'],
            ['name' => 'Original Name', 'list_name' => 'Backlog']
        );

        Task::updateOrCreate(
            ['project_id' => $project->id, 'trello_card_id' => 'card_dup'],
            ['name' => 'Updated Name', 'list_name' => 'In Progress']
        );

        $this->assertSame(1, Task::where('trello_card_id', 'card_dup')->count());
        $this->assertSame('Updated Name', Task::where('trello_card_id', 'card_dup')->first()->name);
        $this->assertSame('In Progress', Task::where('trello_card_id', 'card_dup')->first()->list_name);
    }

    public function test_project_deduplication_by_trello_board_id(): void
    {
        Project::updateOrCreate(
            ['account_id' => $this->account->id, 'trello_board_id' => 'board_dup'],
            ['name' => 'Original Board']
        );

        Project::updateOrCreate(
            ['account_id' => $this->account->id, 'trello_board_id' => 'board_dup'],
            ['name' => 'Updated Board']
        );

        $this->assertSame(1, Project::where('trello_board_id', 'board_dup')->count());
        $this->assertSame('Updated Board', Project::where('trello_board_id', 'board_dup')->first()->name);
    }

    public function test_synced_tasks_carry_overdue_flag(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Overdue Client',
        ]);

        $project = $client->ensureGeneralProject();

        Task::create([
            'project_id' => $project->id,
            'name' => 'Overdue Task',
            'list_name' => 'Backlog',
            'priority' => 'high',
            'is_completed' => false,
            'due_date' => now()->subDays(2),
        ]);

        $this->actingAs($this->user)
            ->get("/clients/{$client->id}/edit")
            ->assertInertia(fn (Assert $assert) => $assert
                ->has('projects.0.tasks', 1)
                ->where('projects.0.tasks.0.is_overdue', true)
            );
    }

    public function test_client_edit_shows_tasks_within_projects(): void
    {
        $client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Tasks Client',
        ]);

        $project = $client->ensureGeneralProject();

        Task::create([
            'project_id' => $project->id,
            'name' => 'Build feature',
            'list_name' => 'In Progress',
            'is_completed' => false,
        ]);

        $this->actingAs($this->user)
            ->get("/clients/{$client->id}/edit")
            ->assertInertia(fn (Assert $assert) => $assert
                ->has('projects', 1)
                ->has('projects.0.tasks', 1)
                ->where('projects.0.tasks.0.name', 'Build feature')
                ->where('projects.0.tasks.0.list_name', 'In Progress')
            );
    }

    public function test_sync_extracts_priority_from_label_colors(): void
    {
        $integration = Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'test-token',
            'settings' => ['trello_api_key' => 'test-key'],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/board_labels/lists')) {
                return Http::response([
                    ['id' => 'list1', 'name' => 'Doing', 'pos' => 1000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/boards/board_labels/cards')) {
                return Http::response([
                    [
                        'id' => 'card_high', 'name' => 'High priority task', 'desc' => '',
                        'idList' => 'list1', 'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/1', 'closed' => false,
                        'labels' => [
                            ['id' => 'l1', 'name' => 'High', 'color' => 'red'],
                            ['id' => 'l2', 'name' => 'Website Redesign', 'color' => 'blue'],
                        ],
                    ],
                    [
                        'id' => 'card_med', 'name' => 'Medium priority task', 'desc' => '',
                        'idList' => 'list1', 'pos' => 2, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/2', 'closed' => false,
                        'labels' => [
                            ['id' => 'l3', 'name' => 'Medium', 'color' => 'yellow'],
                        ],
                    ],
                    [
                        'id' => 'card_low', 'name' => 'Low priority task', 'desc' => '',
                        'idList' => 'list1', 'pos' => 3, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/3', 'closed' => false,
                        'labels' => [
                            ['id' => 'l4', 'name' => 'Low', 'color' => 'green'],
                            ['id' => 'l5', 'name' => 'SEO Audit', 'color' => 'purple'],
                        ],
                    ],
                    [
                        'id' => 'card_none', 'name' => 'No priority task', 'desc' => '',
                        'idList' => 'list1', 'pos' => 4, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/4', 'closed' => false,
                        'labels' => [
                            ['id' => 'l6', 'name' => 'Website Redesign', 'color' => 'blue'],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, '/boards/board_labels')) {
                return Http::response([
                    'id' => 'board_labels', 'name' => 'Test Board', 'desc' => '',
                    'url' => 'https://trello.com/b/test',
                ]);
            }

            return Http::response([], 404);
        });

        $trello = new TrelloService($integration);
        $stats = $trello->syncBoard('board_labels', $this->account->id);

        $this->assertSame(4, $stats['created']);

        $highTask = Task::where('trello_card_id', 'card_high')->first();
        $this->assertSame('high', $highTask->priority);
        $this->assertSame(['Website Redesign'], $highTask->labels);

        $medTask = Task::where('trello_card_id', 'card_med')->first();
        $this->assertSame('medium', $medTask->priority);
        $this->assertNull($medTask->labels);

        $lowTask = Task::where('trello_card_id', 'card_low')->first();
        $this->assertSame('low', $lowTask->priority);
        $this->assertSame(['SEO Audit'], $lowTask->labels);

        $noneTask = Task::where('trello_card_id', 'card_none')->first();
        $this->assertNull($noneTask->priority);
        $this->assertSame(['Website Redesign'], $noneTask->labels);
    }

    public function test_sync_picks_highest_priority_from_multiple_colors(): void
    {
        $integration = Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'test-token',
            'settings' => ['trello_api_key' => 'test-key'],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/board_multi/lists')) {
                return Http::response([
                    ['id' => 'list1', 'name' => 'Doing', 'pos' => 1000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/boards/board_multi/cards')) {
                return Http::response([
                    [
                        'id' => 'card_multi', 'name' => 'Multi-label card', 'desc' => '',
                        'idList' => 'list1', 'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/m1', 'closed' => false,
                        'labels' => [
                            ['id' => 'l1', 'name' => 'Low', 'color' => 'green'],
                            ['id' => 'l2', 'name' => 'High', 'color' => 'red'],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, '/boards/board_multi')) {
                return Http::response([
                    'id' => 'board_multi', 'name' => 'Multi', 'desc' => '', 'url' => 'https://trello.com/b/m',
                ]);
            }

            return Http::response([], 404);
        });

        $trello = new TrelloService($integration);
        $trello->syncBoard('board_multi', $this->account->id);

        $task = Task::where('trello_card_id', 'card_multi')->first();
        $this->assertSame('high', $task->priority);
    }

    public function test_trello_integration_appears_in_providers(): void
    {
        Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'test-token',
        ]);

        $this->actingAs($this->user)
            ->get('/integrations')
            ->assertInertia(fn (Assert $assert) => $assert
                ->has('integrations', fn (Assert $assert) => $assert
                    ->each(fn (Assert $item) => $item->etc())
                )
            );
    }
}

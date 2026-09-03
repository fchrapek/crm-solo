<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProjectTrelloTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

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
        $this->client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'Test Client',
        ]);
        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'test-token',
            'settings' => ['trello_api_key' => 'test-key'],
        ]);
    }

    public function test_disconnect_clears_trello_fields_and_keeps_client(): void
    {
        $project = $this->makeTrelloProject();

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/disconnect-trello")
            ->assertRedirect();

        $project->refresh();

        // Bug regression: project must stay under the client (used to wipe client_id).
        $this->assertSame($this->client->id, $project->client_id);

        $this->assertNull($project->trello_board_id);
        $this->assertNull($project->trello_url);

        $settings = $project->settings ?? [];
        $this->assertArrayNotHasKey('trello_workspace', $settings);
        $this->assertArrayNotHasKey('trello_lists', $settings);
        $this->assertArrayNotHasKey('trello_list_mapping', $settings);
        $this->assertArrayNotHasKey('trello_priority_labels', $settings);
    }

    public function test_disconnect_keeps_existing_trello_tasks_as_history(): void
    {
        $project = $this->makeTrelloProject();

        $task = Task::create([
            'account_id' => $this->account->id,
            'project_id' => $project->id,
            'name' => 'Card from Trello',
            'list_name' => 'Backlog',
            'source' => 'trello',
            'trello_card_id' => 'card_xyz',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/disconnect-trello")
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'project_id' => $project->id,
            'source' => 'trello',
            'trello_card_id' => 'card_xyz',
        ]);
    }

    public function test_disconnect_rejects_non_trello_project(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Private Project',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/disconnect-trello")
            ->assertStatus(422);
    }

    public function test_disconnect_requires_account_ownership(): void
    {
        $other = Account::create(['name' => 'Other']);
        $otherClient = Client::create(['account_id' => $other->id, 'name' => 'Other']);
        $project = Project::create([
            'account_id' => $other->id,
            'client_id' => $otherClient->id,
            'name' => 'Other Project',
            'trello_board_id' => 'b_other',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/disconnect-trello")
            ->assertForbidden();
    }

    public function test_sync_endpoint_pulls_cards_for_this_project_only(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/b_abc/lists')) {
                return Http::response([
                    ['id' => 'l_backlog', 'name' => 'Backlog', 'pos' => 1000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/boards/b_abc/cards')) {
                return Http::response([
                    [
                        'id' => 'card_1',
                        'name' => 'Update plugins',
                        'desc' => '',
                        'idList' => 'l_backlog',
                        'pos' => 1,
                        'due' => null,
                        'dueComplete' => false,
                        'url' => 'https://trello.com/c/card_1',
                        'closed' => false,
                        'labels' => [],
                    ],
                ]);
            }

            if (str_contains($url, '/boards/b_abc')) {
                return Http::response([
                    'id' => 'b_abc',
                    'name' => 'Site Maintenance',
                    'desc' => '',
                    'url' => 'https://trello.com/b/b_abc',
                ]);
            }

            return Http::response([], 404);
        });

        $project = $this->makeTrelloProject();

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'trello_card_id' => 'card_1',
            'name' => 'Update plugins',
            'source' => 'trello',
        ]);
    }

    public function test_sync_rejects_non_trello_project(): void
    {
        $project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Private',
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertStatus(422);
    }

    public function test_update_list_mapping_persists_settings(): void
    {
        $project = $this->makeTrelloProject();

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}/trello-list-mapping", [
                'mapping' => [
                    'l_backlog' => 'Backlog',
                    'l_done' => 'Done',
                ],
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('Backlog', $project->settings['trello_list_mapping']['l_backlog']);
        $this->assertSame('Done', $project->settings['trello_list_mapping']['l_done']);
    }

    public function test_update_list_mapping_rejects_unknown_canonical_lane(): void
    {
        $project = $this->makeTrelloProject();

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}/trello-list-mapping", [
                'mapping' => ['l_backlog' => 'Inbox'],
            ])
            ->assertSessionHasErrors('mapping.l_backlog');
    }

    public function test_update_list_mapping_requires_mapping(): void
    {
        $project = $this->makeTrelloProject();

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}/trello-list-mapping", [])
            ->assertSessionHasErrors('mapping');
    }

    public function test_sync_auto_detects_list_mapping(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/b_zz/lists')) {
                return Http::response([
                    ['id' => 'l_a', 'name' => 'TODO', 'pos' => 1000, 'closed' => false],
                    ['id' => 'l_b', 'name' => 'DOING', 'pos' => 2000, 'closed' => false],
                    ['id' => 'l_c', 'name' => 'DONE', 'pos' => 3000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/boards/b_zz/cards')) {
                return Http::response([
                    [
                        'id' => 'card_a', 'name' => 'A', 'desc' => '', 'idList' => 'l_a',
                        'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/a', 'closed' => false, 'labels' => [],
                    ],
                    [
                        'id' => 'card_c', 'name' => 'C', 'desc' => '', 'idList' => 'l_c',
                        'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/c', 'closed' => false, 'labels' => [],
                    ],
                ]);
            }

            if (str_contains($url, '/boards/b_zz')) {
                return Http::response([
                    'id' => 'b_zz', 'name' => 'Custom Board', 'desc' => '',
                    'url' => 'https://trello.com/b/b_zz',
                ]);
            }

            return Http::response([], 404);
        });

        $project = $this->makeTrelloProject(['trello_board_id' => 'b_zz', 'settings' => []]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertRedirect();

        $project->refresh();
        $mapping = $project->settings['trello_list_mapping'];
        $this->assertSame('To-Do', $mapping['l_a']);
        $this->assertSame('Doing', $mapping['l_b']);
        $this->assertSame('Done', $mapping['l_c']);

        // Tasks get canonical list_name (not the raw Trello name)
        $this->assertDatabaseHas('tasks', [
            'trello_card_id' => 'card_a',
            'list_name' => 'To-Do',
        ]);
        $this->assertDatabaseHas('tasks', [
            'trello_card_id' => 'card_c',
            'list_name' => 'Done',
            'is_completed' => true,
        ]);
    }

    public function test_sync_preserves_locally_renamed_project_and_description(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/b_rename/lists')) {
                return Http::response([
                    ['id' => 'l_a', 'name' => 'Backlog', 'pos' => 1000, 'closed' => false],
                ]);
            }
            if (str_contains($url, '/boards/b_rename/cards')) {
                return Http::response([]);
            }
            if (str_contains($url, '/boards/b_rename')) {
                return Http::response([
                    'id' => 'b_rename',
                    'name' => 'Trello Board Name',
                    'desc' => 'Trello-side description',
                    'url' => 'https://trello.com/b/b_rename',
                ]);
            }

            return Http::response([], 404);
        });

        // User has already renamed the project locally (after a previous sync).
        $project = $this->makeTrelloProject([
            'trello_board_id' => 'b_rename',
            'name' => 'My Local Name',
            'description' => 'Local description',
            'settings' => [],
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('My Local Name', $project->name);
        $this->assertSame('Local description', $project->description);
    }

    public function test_sync_mirrors_card_closed_to_archived_at(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/b_arch/lists')) {
                return Http::response([
                    ['id' => 'l_a', 'name' => 'Backlog', 'pos' => 1000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/boards/b_arch/cards')) {
                return Http::response([
                    [
                        'id' => 'card_open', 'name' => 'Open one', 'desc' => '', 'idList' => 'l_a',
                        'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/o', 'closed' => false, 'labels' => [],
                    ],
                    [
                        'id' => 'card_closed', 'name' => 'Archived one', 'desc' => '', 'idList' => 'l_a',
                        'pos' => 2, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/x', 'closed' => true, 'labels' => [],
                    ],
                ]);
            }

            if (str_contains($url, '/boards/b_arch')) {
                return Http::response([
                    'id' => 'b_arch', 'name' => 'B', 'desc' => '', 'url' => 'https://trello.com/b/b_arch',
                ]);
            }

            return Http::response([], 404);
        });

        $project = $this->makeTrelloProject(['trello_board_id' => 'b_arch', 'settings' => []]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertRedirect();

        $open = Task::where('trello_card_id', 'card_open')->first();
        $this->assertNotNull($open);
        $this->assertNull($open->archived_at);

        $closed = Task::where('trello_card_id', 'card_closed')->first();
        $this->assertNotNull($closed);
        $this->assertNotNull($closed->archived_at);
    }

    public function test_sync_unarchives_when_card_reopened_on_trello(): void
    {
        $project = $this->makeTrelloProject(['trello_board_id' => 'b_re', 'settings' => []]);

        // Existing task pre-archived locally (mirroring earlier sync).
        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_re',
            'source' => 'trello',
            'name' => 'Comeback',
            'list_name' => 'Backlog',
            'archived_at' => now()->subDay(),
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/b_re/lists')) {
                return Http::response([
                    ['id' => 'l_a', 'name' => 'Backlog', 'pos' => 1000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/boards/b_re/cards')) {
                return Http::response([
                    [
                        'id' => 'card_re', 'name' => 'Comeback', 'desc' => '', 'idList' => 'l_a',
                        'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/r', 'closed' => false, 'labels' => [],
                    ],
                ]);
            }

            if (str_contains($url, '/boards/b_re')) {
                return Http::response([
                    'id' => 'b_re', 'name' => 'B', 'desc' => '', 'url' => 'https://trello.com/b/b_re',
                ]);
            }

            return Http::response([], 404);
        });

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertRedirect();

        $task = Task::where('trello_card_id', 'card_re')->first();
        $this->assertNull($task->archived_at);
    }

    public function test_sync_preserves_existing_user_mapping(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/b_user/lists')) {
                return Http::response([
                    ['id' => 'l_q', 'name' => 'Queue', 'pos' => 1000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/boards/b_user/cards')) {
                return Http::response([
                    [
                        'id' => 'card_q', 'name' => 'Q', 'desc' => '', 'idList' => 'l_q',
                        'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/q', 'closed' => false, 'labels' => [],
                    ],
                ]);
            }

            if (str_contains($url, '/boards/b_user')) {
                return Http::response([
                    'id' => 'b_user', 'name' => 'B', 'desc' => '', 'url' => 'https://trello.com/b/b_user',
                ]);
            }

            return Http::response([], 404);
        });

        // User has explicitly mapped Queue → Doing (overriding the auto-detect
        // which would call it Backlog or To-Do).
        $project = $this->makeTrelloProject([
            'trello_board_id' => 'b_user',
            'settings' => [
                'trello_list_mapping' => ['l_q' => 'Doing'],
            ],
        ]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('Doing', $project->settings['trello_list_mapping']['l_q']);
        $this->assertDatabaseHas('tasks', [
            'trello_card_id' => 'card_q',
            'list_name' => 'Doing',
        ]);
    }

    public function test_update_list_mapping_applies_to_existing_tasks(): void
    {
        $project = $this->makeTrelloProject([
            'settings' => [
                'trello_lists' => [
                    ['id' => 'l_backlog', 'name' => 'Backlog'],
                    ['id' => 'l_done', 'name' => 'Done'],
                ],
                'trello_list_mapping' => [
                    'l_backlog' => 'Backlog',
                    'l_done' => 'Backlog',
                ],
            ],
        ]);

        // Two tasks pre-existing on the board, both stuck in Backlog because
        // the mapping previously treated both Trello lists as Backlog.
        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_a',
            'trello_list_id' => 'l_backlog',
            'source' => 'trello',
            'name' => 'A',
            'list_name' => 'Backlog',
            'is_completed' => false,
        ]);
        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_b',
            'trello_list_id' => 'l_done',
            'source' => 'trello',
            'name' => 'B',
            'list_name' => 'Backlog',
            'is_completed' => false,
        ]);

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}/trello-list-mapping", [
                'mapping' => [
                    'l_backlog' => 'Backlog',
                    'l_done' => 'Done',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'trello_card_id' => 'card_a',
            'list_name' => 'Backlog',
            'is_completed' => false,
        ]);
        $this->assertDatabaseHas('tasks', [
            'trello_card_id' => 'card_b',
            'list_name' => 'Done',
            'is_completed' => true,
        ]);
    }

    public function test_update_list_mapping_accepts_custom_lanes(): void
    {
        $project = $this->makeTrelloProject([
            'settings' => [
                'trello_lists' => [
                    ['id' => 'l_q', 'name' => 'Awaiting Client'],
                ],
            ],
        ]);

        Task::create([
            'project_id' => $project->id,
            'trello_card_id' => 'card_q',
            'trello_list_id' => 'l_q',
            'source' => 'trello',
            'name' => 'Q',
            'list_name' => 'Backlog',
        ]);

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}/trello-list-mapping", [
                'mapping' => ['l_q' => 'Awaiting client'],
                'custom_lanes' => ['Awaiting client', '', '  ', 'Backlog'],
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame(['Awaiting client'], $project->settings['custom_lanes']);
        $this->assertSame('Awaiting client', $project->settings['trello_list_mapping']['l_q']);
        $this->assertDatabaseHas('tasks', [
            'trello_card_id' => 'card_q',
            'list_name' => 'Awaiting client',
        ]);
    }

    public function test_update_list_mapping_rejects_lane_not_in_canonical_or_custom(): void
    {
        $project = $this->makeTrelloProject();

        $this->actingAs($this->user)
            ->put("/projects/{$project->id}/trello-list-mapping", [
                'mapping' => ['l_q' => 'Awaiting client'],
                'custom_lanes' => ['Different Lane'],
            ])
            ->assertSessionHasErrors('mapping.l_q');
    }

    public function test_sync_stores_trello_list_id_on_tasks(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/boards/b_id/lists')) {
                return Http::response([
                    ['id' => 'list_xyz', 'name' => 'Backlog', 'pos' => 1000, 'closed' => false],
                ]);
            }
            if (str_contains($url, '/boards/b_id/cards')) {
                return Http::response([
                    [
                        'id' => 'card_xyz', 'name' => 'X', 'desc' => '', 'idList' => 'list_xyz',
                        'pos' => 1, 'due' => null, 'dueComplete' => false,
                        'url' => 'https://trello.com/c/x', 'closed' => false, 'labels' => [],
                    ],
                ]);
            }
            if (str_contains($url, '/boards/b_id')) {
                return Http::response([
                    'id' => 'b_id', 'name' => 'B', 'desc' => '', 'url' => 'https://trello.com/b/b_id',
                ]);
            }

            return Http::response([], 404);
        });

        $project = $this->makeTrelloProject(['trello_board_id' => 'b_id', 'settings' => []]);

        $this->actingAs($this->user)
            ->post("/projects/{$project->id}/sync-trello")
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'trello_card_id' => 'card_xyz',
            'trello_list_id' => 'list_xyz',
        ]);
    }

    private function makeTrelloProject(array $overrides = []): Project
    {
        return Project::create(array_merge([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Site Maintenance',
            'trello_board_id' => 'b_abc',
            'trello_url' => 'https://trello.com/b/b_abc',
            'settings' => [
                'trello_workspace' => 'Solo',
                'trello_lists' => [
                    ['id' => 'l_backlog', 'name' => 'Backlog'],
                    ['id' => 'l_done', 'name' => 'Done'],
                ],
                'trello_list_mapping' => [
                    'l_backlog' => 'Backlog',
                    'l_done' => 'Done',
                ],
                'trello_priority_labels' => ['red' => 'lbl_high'],
            ],
        ], $overrides));
    }
}

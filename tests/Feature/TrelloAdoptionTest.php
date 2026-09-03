<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use App\Services\Integrations\Trello\TrelloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sync is opt-in.
 *
 * The Trello token can read every board its owner has ever been added to —
 * personal boards, a friend's flat renovation, an ex-client's leftovers. The
 * old syncAllBoards() imported all of them as client-less projects. These tests
 * pin the rule that replaced it: a board is synced only once the CRM has
 * adopted it, and archiving a project un-adopts it for good.
 */
final class TrelloAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Test Account']);

        User::factory()->create([
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
            'settings' => ['trello_api_key' => 'test-key'],
        ]);
    }

    public function test_a_board_with_no_project_is_never_imported(): void
    {
        $this->fakeTrelloWith(['board_stranger']);

        $stats = (new TrelloService($this->integration))->syncAllBoards($this->account->id);

        $this->assertSame(0, Project::count(), 'An unadopted board must not create a project.');
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['boards']);
    }

    public function test_an_adopted_board_still_syncs(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'Test Client']);

        Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'trello_board_id' => 'board_mine',
            'name' => 'Adopted board',
        ]);

        $this->fakeTrelloWith(['board_mine']);

        $stats = (new TrelloService($this->integration))->syncAllBoards($this->account->id);

        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(1, $stats['boards']);
        $this->assertSame(1, $stats['created'], 'The board card should have synced.');
    }

    public function test_an_archived_project_is_not_resurrected_by_sync(): void
    {
        Project::create([
            'account_id' => $this->account->id,
            'client_id' => null,
            'trello_board_id' => 'board_dismissed',
            'name' => 'Old Trello import',
            'archived_at' => now(),
        ]);

        $this->fakeTrelloWith(['board_dismissed']);

        $stats = (new TrelloService($this->integration))->syncAllBoards($this->account->id);

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['created'], 'Archiving must be a tombstone, not a pause.');

        $project = Project::where('trello_board_id', 'board_dismissed')->first();
        $this->assertNotNull($project, 'The row stays — archive keeps history.');
        $this->assertNotNull($project->archived_at, 'Sync must not clear the tombstone.');
    }

    public function test_a_project_without_a_client_still_syncs_while_unarchived(): void
    {
        // Adopting before the client exists is legitimate — the connect dialog
        // creates the project first and the client mapping lands later.
        Project::create([
            'account_id' => $this->account->id,
            'client_id' => null,
            'trello_board_id' => 'board_pending',
            'name' => 'Awaiting a client',
        ]);

        $this->fakeTrelloWith(['board_pending']);

        $stats = (new TrelloService($this->integration))->syncAllBoards($this->account->id);

        $this->assertSame(0, $stats['skipped'], 'No client is not the same as not adopted.');
        $this->assertSame(1, $stats['boards']);
    }

    /**
     * Fake the two endpoints syncAllBoards walks: the board list, then per-board
     * detail, lists and cards. Every board gets exactly one card.
     *
     * @param  string[]  $boardIds
     */
    private function fakeTrelloWith(array $boardIds): void
    {
        $boards = array_map(fn (string $id): array => [
            'id' => $id,
            'name' => 'Board '.$id,
            'desc' => '',
            'url' => 'https://trello.com/b/'.$id,
            'closed' => false,
        ], $boardIds);

        Http::fake(function ($request) use ($boards) {
            $url = $request->url();

            if (str_contains($url, '/members/me/boards')) {
                return Http::response($boards);
            }

            if (str_contains($url, '/lists')) {
                return Http::response([
                    ['id' => 'list1', 'name' => 'Doing', 'pos' => 1000, 'closed' => false],
                ]);
            }

            if (str_contains($url, '/cards')) {
                return Http::response([[
                    'id' => 'card_'.md5($url), 'name' => 'A card', 'desc' => '',
                    'idList' => 'list1', 'pos' => 1, 'due' => null, 'dueComplete' => false,
                    'url' => 'https://trello.com/c/1', 'closed' => false, 'labels' => [],
                ]]);
            }

            foreach ($boards as $board) {
                if (str_contains($url, '/boards/'.$board['id'])) {
                    return Http::response($board);
                }
            }

            return Http::response([], 404);
        });
    }
}

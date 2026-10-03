<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Services\Integrations\Trello\TrelloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A card's description is the client's text: the sync stores it exactly as
 * Trello holds it, and the humanizer backfill leaves it alone.
 */
final class TrelloDescriptionVerbatimTest extends TestCase
{
    use RefreshDatabase;

    private const string CLIENT_TEXT = "Zmień stopkę — „pilne” i “ważne”…\nCytat: 'x'";

    public function test_the_sync_stores_a_card_description_verbatim_and_the_backfill_skips_it(): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create();
        $client = Client::factory()->create(['account_id' => $account->id]);
        Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $integration = Integration::create(['account_id' => $account->id, 'provider' => 'trello', 'is_enabled' => true, 'api_key' => 't', 'settings' => ['trello_api_key' => 'k']]);
        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), '/boards/b1/lists') => Http::response([['id' => 'l1', 'name' => 'To Do']]),
            str_contains($request->url(), '/boards/b1/cards') => Http::response([[
                'id' => 'card1', 'name' => 'Karta', 'desc' => self::CLIENT_TEXT, 'idList' => 'l1', 'pos' => 1, 'due' => null,
                'dueComplete' => false, 'url' => 'https://trello.com/c/1', 'closed' => false, 'labels' => [],
                'dateLastActivity' => '2026-10-01T10:00:00.000Z',
            ]]),
            str_contains($request->url(), '/boards/b1') => Http::response(['id' => 'b1', 'name' => 'Board', 'desc' => '', 'url' => 'https://trello.com/b/1']),
            default => Http::response([], 404),
        });

        (new TrelloService($integration))->syncBoard('b1', $account->id);
        $this->assertSame(self::CLIENT_TEXT, Task::sole()->description);

        $this->artisan('crm:humanize --write')->assertSuccessful();
        $this->assertSame(self::CLIENT_TEXT, Task::sole()->fresh()->description);
    }

    public function test_the_backfill_still_cleans_a_manual_task(): void
    {
        $account = Account::factory()->create();
        $project = Project::create(['account_id' => $account->id, 'name' => 'P']);
        $task = Task::create(['project_id' => $project->id, 'name' => 'M', 'source' => 'manual']);
        DB::table('tasks')->where('id', $task->id)->update(['description' => 'a — b']);

        $this->artisan('crm:humanize --write')->assertSuccessful();

        $this->assertSame('a - b', $task->fresh()->description);
    }
}

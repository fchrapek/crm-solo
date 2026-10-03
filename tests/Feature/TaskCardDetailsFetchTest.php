<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCardDetails;
use App\Services\Agent\TaskReader;
use App\Services\Integrations\Trello\TrelloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A card's checklists and comments arrive when an agent opens the task, once
 * per card version: never from the sync, never on the demo, and a Trello
 * failure still returns the record.
 */
final class TaskCardDetailsFetchTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->account = Account::factory()->create();
        $client = Client::factory()->create(['account_id' => $this->account->id]);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Board', 'trello_board_id' => 'board1']);
        Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'token-value',
            'settings' => ['trello_api_key' => 'key-value'],
        ]);
        $this->task = Task::create([
            'project_id' => $project->id,
            'name' => 'Card',
            'source' => 'trello',
            'trello_card_id' => 'abc123',
            'list_name' => 'Doing',
            'trello_activity_at' => '2026-10-01 10:00:00',
        ]);
    }

    public function test_the_first_read_fetches_the_card_in_one_request_and_stores_its_details(): void
    {
        $this->fakeCard();

        $record = app(TaskReader::class)->read($this->task);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.trello.com/1/cards/abc123?')
                && $query['checklists'] === 'all'
                && $query['attachments'] === 'true'
                && $query['actions'] === 'commentCard';
        });
        $this->assertSame([['name' => 'QA', 'items' => [
            ['name' => 'Sprawdź stopkę', 'done' => true],
            ['name' => 'Sprawdź menu', 'done' => false],
        ]]], $record['checklists']);
        $this->assertSame(['Anna K', 'Jan'], array_column($record['comments'], 'author'));
        $this->assertSame('Stopka — nowa wersja, “pilne”', $record['comments'][0]['text']);
        $this->assertNotNull($record['source']['details_fetched_at']);
        $this->assertNull($record['source']['fetch_error']);
    }

    public function test_a_second_read_of_an_unchanged_card_uses_the_cache(): void
    {
        $this->fakeCard();
        app(TaskReader::class)->read($this->task);

        $record = app(TaskReader::class)->read($this->task->fresh());

        Http::assertSentCount(1);
        $this->assertCount(1, $record['checklists']);
    }

    public function test_newer_card_activity_refetches(): void
    {
        $this->fakeCard();
        app(TaskReader::class)->read($this->task);

        $this->task->update(['trello_activity_at' => '2026-10-01 12:00:00']);
        app(TaskReader::class)->read($this->task->fresh());

        Http::assertSentCount(2);
    }

    public function test_refresh_forces_a_fetch(): void
    {
        $this->fakeCard();
        app(TaskReader::class)->read($this->task);

        app(TaskReader::class)->read($this->task->fresh(), refresh: true);

        Http::assertSentCount(2);
    }

    public function test_a_trello_failure_returns_the_cached_record_with_the_error(): void
    {
        TaskCardDetails::create([
            'task_id' => $this->task->id,
            'checklists' => [['name' => 'Cached', 'items' => []]],
            'comments' => [],
            'card_activity_at' => '2026-10-01 09:00:00',
            'fetched_at' => '2026-10-01 09:00:00',
        ]);
        Http::fake(['api.trello.com/*' => Http::response(['message' => 'down'], 500)]);

        $record = app(TaskReader::class)->read($this->task);

        $this->assertSame('Trello answered HTTP 500.', $record['source']['fetch_error']);
        $this->assertSame('Cached', $record['checklists'][0]['name']);
        $this->assertSame('2026-10-01 09:00:00', TaskCardDetails::first()->fetched_at->toDateTimeString());
    }

    public function test_an_unreachable_trello_still_returns_the_record(): void
    {
        Http::fake(['api.trello.com/*' => fn () => throw new ConnectionException('timed out')]);

        $record = app(TaskReader::class)->read($this->task);

        $this->assertSame('Trello could not be reached.', $record['source']['fetch_error']);
        $this->assertSame([], $record['checklists']);
    }

    public function test_demo_mode_never_fetches(): void
    {
        config(['app.demo' => true]);
        Http::fake();

        $record = app(TaskReader::class)->read($this->task, refresh: true);

        Http::assertNothingSent();
        $this->assertNull($record['source']['fetch_error']);
        $this->assertSame(0, TaskCardDetails::count());
    }

    public function test_a_manual_task_never_calls_trello(): void
    {
        Http::fake();
        $manual = Task::create(['project_id' => $this->task->project_id, 'name' => 'Manual', 'source' => 'manual']);

        app(TaskReader::class)->read($manual, refresh: true);

        Http::assertNothingSent();
    }

    public function test_only_the_tasks_own_account_integration_is_used(): void
    {
        Http::fake();
        Integration::query()->update(['account_id' => Account::factory()->create()->id]);

        $record = app(TaskReader::class)->read($this->task);

        Http::assertNothingSent();
        $this->assertSame('No Trello integration is configured for this account.', $record['source']['fetch_error']);
    }

    public function test_a_board_sync_never_fetches_card_details(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/boards/board1/lists') => Http::response([['id' => 'l1', 'name' => 'Doing']]),
                str_contains($url, '/boards/board1/cards') => Http::response([[
                    'id' => 'abc123', 'name' => 'Card', 'desc' => '', 'idList' => 'l1', 'pos' => 1, 'due' => null,
                    'dueComplete' => false, 'url' => 'https://trello.com/c/1', 'closed' => false, 'labels' => [],
                    'dateLastActivity' => '2026-10-01T13:00:00.000Z',
                ]]),
                str_contains($url, '/boards/board1') => Http::response(['id' => 'board1', 'name' => 'Board', 'desc' => '', 'url' => 'https://trello.com/b/1']),
                default => Http::response([], 404),
            };
        });

        (new TrelloService(Integration::firstOrFail()))->syncBoard('board1', $this->account->id);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/cards/'));
        $this->assertSame(0, TaskCardDetails::count());
    }

    private function fakeCard(): void
    {
        Http::fake(['api.trello.com/1/cards/abc123*' => Http::response([
            'id' => 'abc123',
            'dateLastActivity' => '2026-10-01T10:00:00.000Z',
            'checklists' => [[
                'id' => 'cl1', 'name' => 'QA', 'pos' => 1,
                'checkItems' => [
                    ['id' => 'i2', 'name' => 'Sprawdź menu', 'state' => 'incomplete', 'pos' => 2],
                    ['id' => 'i1', 'name' => 'Sprawdź stopkę', 'state' => 'complete', 'pos' => 1],
                ],
            ]],
            'attachments' => [],
            'actions' => [
                ['id' => 'a2', 'type' => 'commentCard', 'date' => '2026-10-01T09:30:00.000Z', 'data' => ['text' => 'Zrobione?'], 'memberCreator' => ['fullName' => 'Jan', 'username' => 'jan']],
                ['id' => 'a1', 'type' => 'commentCard', 'date' => '2026-09-30T08:00:00.000Z', 'data' => ['text' => 'Stopka — nowa wersja, “pilne”'], 'memberCreator' => ['fullName' => 'Anna K', 'username' => 'annak']],
            ],
        ])]);
    }
}

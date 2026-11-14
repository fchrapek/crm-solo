<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\TaskList;
use App\Services\Day\DayPlanner;
use App\Services\Integrations\Trello\CardFinishReconciler;
use App\Services\Integrations\Trello\TrelloListMapper;
use App\Services\Integrations\Trello\TrelloService;
use App\Services\Tasks\TaskCompletion;
use App\Support\LocalCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TrelloSkippedColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Integration $integration;

    private Project $project;

    /** @var array<string, string> card id => list id */
    private array $cardLists = [];

    /** @var array<string, string> card id => list id */
    private array $board2Lists = [];

    /** @var list<string> */
    private array $closedCards = [];

    private bool $reportsArchived = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Studio']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->integration = Integration::create([
            'account_id' => $this->account->id,
            'provider' => 'trello',
            'is_enabled' => true,
            'api_key' => 'test-token',
            'settings' => ['trello_api_key' => 'test-key', 'skipped_lists' => ['Raporty']],
        ]);
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'Acme']);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $client->id,
            'trello_board_id' => 'board1',
            'name' => 'Acme board',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            preg_match('#/boards/(board\d)(/lists|/cards)?#', $url, $match);
            $board = $match[1] ?? null;
            $cards = $board === 'board2' ? $this->board2Lists : $this->cardLists;

            if (($match[2] ?? null) === '/lists') {
                return Http::response([
                    ['id' => 'doing', 'name' => 'Doing', 'pos' => 1, 'closed' => false],
                    ['id' => 'reports', 'name' => '  raporty ', 'pos' => 2, 'closed' => $this->reportsArchived],
                ]);
            }

            if (($match[2] ?? null) === '/cards') {
                return Http::response(collect($cards)->map(fn (string $list, string $card): array => [
                    'id' => $card, 'name' => "Card {$card}", 'desc' => '', 'idList' => $list, 'pos' => 1,
                    'due' => null, 'dueComplete' => false, 'url' => "https://trello.com/c/{$card}",
                    'closed' => in_array($card, $this->closedCards, true), 'labels' => [],
                ])->values()->all());
            }

            if ($board !== null) {
                return Http::response(['id' => $board, 'name' => "Board {$board}", 'desc' => '', 'url' => "https://trello.com/b/{$board}"]);
            }

            return Http::response([], 404);
        });
    }

    public function test_a_card_in_a_skipped_column_is_not_an_open_task_whatever_the_case_and_spacing(): void
    {
        $this->cardLists = ['work' => 'doing', 'report' => 'reports'];

        $this->sync();

        $this->assertSame(['work'], Task::open()->pluck('trello_card_id')->all());
        $this->assertNotNull(Task::where('trello_card_id', 'report')->sole()->archived_at);
    }

    public function test_a_card_moved_into_a_skipped_column_drops_out_and_comes_back_when_moved_out(): void
    {
        $this->cardLists = ['card' => 'doing'];
        $this->sync();
        $task = Task::where('trello_card_id', 'card')->sole();
        $this->assertNull($task->archived_at);

        $this->cardLists = ['card' => 'reports'];
        $this->sync();
        $this->assertSame([true, true], [$task->fresh()->archived_at !== null, $task->fresh()->trello_skipped]);
        $this->assertSame(1, Task::where('trello_card_id', 'card')->count());

        $this->cardLists = ['card' => 'doing'];
        $this->sync();
        $this->assertSame([null, false], [$task->fresh()->archived_at, $task->fresh()->trello_skipped]);
    }

    public function test_hours_logged_on_a_card_stay_with_it_after_its_column_is_skipped(): void
    {
        $this->cardLists = ['card' => 'doing'];
        $this->sync();
        $task = Task::where('trello_card_id', 'card')->sole();
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'client_id' => $this->project->client_id,
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'duration_minutes' => 60,
            'billable' => true,
        ]);

        $this->cardLists = ['card' => 'reports'];
        $this->sync();

        $this->assertSame([$task->id, 60], [$entry->fresh()->task_id, $entry->fresh()->duration_minutes]);
    }

    public function test_without_skipped_columns_every_card_stays_a_task(): void
    {
        $this->integration->update(['settings' => ['trello_api_key' => 'test-key']]);
        $this->cardLists = ['work' => 'doing', 'report' => 'reports'];

        $this->sync();

        $this->assertSame(2, Task::open()->count());
    }

    public function test_saving_skipped_columns_tidies_the_names_and_keeps_the_credentials(): void
    {
        $this->actingAs($this->user)
            ->put(route('integrations.update', 'trello'), [
                'is_enabled' => true,
                'settings' => ['skipped_lists' => ['  Raporty ', 'raporty', 'Urlopy   nowe', '', 'Backlog']],
            ])
            ->assertSessionHasNoErrors();

        $settings = $this->integration->fresh()->settings;
        $this->assertSame(['Raporty', 'Urlopy nowe', 'Backlog'], $settings['skipped_lists']);
        $this->assertSame('test-key', $settings['trello_api_key']);
    }

    public function test_an_empty_list_clears_the_skipped_columns(): void
    {
        $this->actingAs($this->user)
            ->put(route('integrations.update', 'trello'), ['is_enabled' => true, 'settings' => ['skipped_lists' => []]])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->integration->fresh()->settings['skipped_lists']);
    }

    public function test_a_column_name_must_be_text_of_reasonable_length(): void
    {
        $this->actingAs($this->user)
            ->put(route('integrations.update', 'trello'), ['is_enabled' => true, 'settings' => ['skipped_lists' => [str_repeat('x', 101)]]])
            ->assertSessionHasErrors('settings.skipped_lists.0');
    }

    public function test_the_settings_page_offers_the_columns_seen_on_this_accounts_synced_boards(): void
    {
        $this->project->update(['settings' => ['trello_lists' => [['id' => 'a', 'name' => 'Doing'], ['id' => 'b', 'name' => 'Raporty']]]]);
        Project::create([
            'account_id' => $this->account->id,
            'trello_board_id' => 'board2',
            'name' => 'Second',
            'settings' => ['trello_lists' => [['id' => 'c', 'name' => 'doing'], ['id' => 'd', 'name' => 'Urlopy']]],
        ]);
        Project::create([
            'account_id' => $this->account->id,
            'trello_board_id' => 'board3',
            'name' => 'Archived',
            'archived_at' => now(),
            'settings' => ['trello_lists' => [['id' => 'e', 'name' => 'Old column']]],
        ]);
        $other = Account::create(['name' => 'Other']);
        Project::create([
            'account_id' => $other->id,
            'trello_board_id' => 'board4',
            'name' => 'Theirs',
            'settings' => ['trello_lists' => [['id' => 'f', 'name' => 'Secret column']]],
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.show', ['tab' => 'integracje']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('integrations.1.provider', 'trello')
                ->where('integrations.1.skipped_lists', ['Raporty'])
                ->where('integrations.1.seen_lists', ['Doing', 'Raporty', 'Urlopy']));
    }

    public function test_a_ticked_card_filed_into_a_skipped_column_keeps_its_tick_without_asking_trello(): void
    {
        $this->cardLists = ['card' => 'doing'];
        $this->sync();
        $task = Task::where('trello_card_id', 'card')->sole();
        $task->update(['finished_at' => now()->subDay()]);
        $finished = $task->fresh()->finished_at->toDateTimeString();

        $this->cardLists = ['card' => 'reports'];
        $this->sync();

        $this->assertSame($finished, $task->fresh()->finished_at?->toDateTimeString());
        $this->assertNotNull($task->fresh()->archived_at);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/actions'));
    }

    public function test_a_card_moved_to_another_board_of_the_client_into_a_skipped_column_keeps_one_task_and_its_hours(): void
    {
        Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->project->client_id,
            'trello_board_id' => 'board2',
            'name' => 'Acme second board',
        ]);
        $this->cardLists = ['card' => 'doing'];
        $this->sync();
        $task = Task::where('trello_card_id', 'card')->sole();
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'client_id' => $this->project->client_id,
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'duration_minutes' => 60,
            'billable' => true,
        ]);

        $this->cardLists = [];
        $this->board2Lists = ['card' => 'reports'];
        $this->sync('board2');

        $this->assertSame(1, Task::where('trello_card_id', 'card')->count());
        $this->assertNotNull($task->fresh()->archived_at);
        $this->assertSame($task->id, $entry->fresh()->task_id);
    }

    public function test_only_trello_takes_skipped_columns(): void
    {
        $this->actingAs($this->user)
            ->put(route('integrations.update', 'infakt'), ['is_enabled' => false, 'settings' => ['skipped_lists' => ['Raporty']]])
            ->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('skipped_lists', Integration::where('provider', 'infakt')->sole()->settings ?? []);
    }

    public function test_a_card_closed_on_trello_stays_archived_when_its_column_stops_being_skipped(): void
    {
        $this->cardLists = ['card' => 'reports'];
        $this->closedCards = ['card'];
        $this->sync();
        $this->integration->update(['settings' => ['trello_api_key' => 'test-key', 'skipped_lists' => []]]);

        $this->sync();

        $task = Task::where('trello_card_id', 'card')->sole();
        $this->assertSame([true, false], [$task->archived_at !== null, $task->trello_skipped]);
    }

    public function test_a_skipped_column_archived_on_trello_keeps_its_cards_out(): void
    {
        $this->cardLists = ['card' => 'reports'];
        $this->reportsArchived = true;

        $this->sync();

        $task = Task::where('trello_card_id', 'card')->sole();
        $this->assertSame([true, true], [$task->archived_at !== null, $task->trello_skipped]);
        $this->assertSame(['Doing'], array_column($this->project->fresh()->settings['trello_lists'], 'name'));
    }

    public function test_mapping_a_skipped_column_to_done_never_ticks_its_cards(): void
    {
        $this->cardLists = ['card' => 'reports'];
        $this->sync();
        $task = Task::where('trello_card_id', 'card')->sole();
        $settings = $this->project->fresh()->settings;
        $settings['trello_list_mapping']['reports'] = TrelloListMapper::LANE_DONE;
        $this->project->update(['settings' => $settings]);

        CardFinishReconciler::remap($task);

        $this->assertNull($task->fresh()->finished_at);
        $this->assertSame(0, Task::query()->work()->where('trello_card_id', 'card')->count());
    }

    public function test_an_agent_done_list_and_a_projects_progress_leave_out_a_skipped_card(): void
    {
        $this->cardLists = ['card' => 'reports', 'work' => 'doing'];
        $this->sync();
        Task::where('trello_card_id', 'card')->update(['is_completed' => true]);
        Task::where('trello_card_id', 'work')->update(['finished_at' => now()]);

        $done = app(TaskList::class)->list($this->account->id, ['status' => 'done']);
        $this->assertSame(['work'], Task::whereIn('id', array_column($done['tasks'], 'id'))->pluck('trello_card_id')->all());

        $tasks = $this->actingAs($this->user)->get("/clients/{$this->project->client_id}/projects/{$this->project->id}")->viewData('page')['props']['tasks'];
        $this->assertSame(1, $tasks['done']['count']);
        $this->assertSame(0, array_sum(array_column($tasks['lanes'], 'count')));
    }

    public function test_a_picked_card_filed_into_a_skipped_column_mapped_to_done_is_not_done_on_its_day(): void
    {
        $this->cardLists = ['card' => 'doing', 'ticked' => 'doing'];
        $this->sync();
        $planner = app(DayPlanner::class);
        $today = LocalCalendar::today();
        foreach (['card', 'ticked'] as $card) {
            $planner->addPick($this->account->id, $today, Task::where('trello_card_id', $card)->sole()->id);
        }
        Task::where('trello_card_id', 'ticked')->update(['finished_at' => now()]);

        $this->cardLists = ['card' => 'reports', 'ticked' => 'reports'];
        $this->sync();
        $settings = $this->project->fresh()->settings;
        $settings['trello_list_mapping']['reports'] = TrelloListMapper::LANE_DONE;
        $this->project->update(['settings' => $settings]);
        Task::where('project_id', $this->project->id)->get()->each(CardFinishReconciler::remap(...));

        $done = $planner->picks($this->account->id, $today)->mapWithKeys(fn (array $row): array => [Task::find($row['task_id'])->trello_card_id => $row['done']]);
        $this->assertSame(['card' => false, 'ticked' => true], $done->sortKeys()->all());
    }

    public function test_a_skipped_card_in_a_column_mapped_to_done_can_be_ticked_and_unticked(): void
    {
        $this->cardLists = ['card' => 'reports'];
        $this->sync();
        $settings = $this->project->fresh()->settings;
        $settings['trello_list_mapping']['reports'] = TrelloListMapper::LANE_DONE;
        $this->project->update(['settings' => $settings]);
        $task = Task::where('trello_card_id', 'card')->sole();
        CardFinishReconciler::remap($task);
        $completion = app(TaskCompletion::class);

        $completion->finish($task->fresh());
        $this->assertSame([true, true], [$task->fresh()->isDone(), $task->fresh()->canUnfinish()]);

        $completion->unfinish($task->fresh());
        $this->assertSame([null, false], [$task->fresh()->finished_at, $task->fresh()->isDone()]);
    }

    public function test_a_completed_card_gets_its_finish_when_its_column_stops_being_skipped(): void
    {
        $this->cardLists = ['card' => 'reports'];
        $this->sync();
        $settings = $this->project->fresh()->settings;
        $settings['trello_list_mapping']['reports'] = TrelloListMapper::LANE_DONE;
        $this->project->update(['settings' => $settings]);
        $this->sync();
        $this->assertNull(Task::where('trello_card_id', 'card')->sole()->finished_at);

        $this->integration->update(['settings' => ['trello_api_key' => 'test-key', 'skipped_lists' => []]]);
        $this->sync();

        $task = Task::where('trello_card_id', 'card')->sole();
        $this->assertSame([false, true], [$task->trello_skipped, $task->finished_at !== null]);
    }

    private function sync(string $board = 'board1'): void
    {
        (new TrelloService($this->integration->fresh()))->syncBoard($board, $this->account->id);
    }
}

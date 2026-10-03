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
use App\Services\Integrations\Trello\CardFinishReconciler;
use App\Services\Integrations\Trello\TrelloBoardSyncRunning;
use App\Services\Integrations\Trello\TrelloService;
use App\Services\Reports\ReportDataAggregator;
use App\Services\Tasks\TaskCompletion;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Trello owns the card, the CRM owns the owner's finish. The sync keeps a
 * tick made in the CRM, fills one for a card finished in Trello, and drops it
 * only when the client sends the card back to an active list.
 */
final class TrelloSyncFinishedTest extends TestCase
{
    use RefreshDatabase;

    private const LISTS = [
        'l_todo' => 'To Do',
        'l_doing' => 'Doing',
        'l_test' => 'Testing',
        'l_done' => 'Done',
    ];

    private Account $account;

    private Project $project;

    private Integration $integration;

    private string $cardList = 'l_doing';

    private bool $dueComplete = false;

    /** When the card last changed on Trello; null means "just now", at the sync. */
    private ?string $cardActivity = null;

    /** When the card last changed list; null means "just now", at the sync. */
    private ?string $moveAt = null;

    private bool $moveLookupFails = false;

    /** Cards board_1 returns besides card1. */
    private array $extraCards = [];

    private array $board2Cards = [];

    /** Replaces board_1's whole cards response (a failure, a truncated list). */
    private mixed $cardsResponse = null;

    /** Runs while board_1's cards are fetched, e.g. to let the clock run. */
    private ?Closure $onCardsFetch = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));
        $this->account = Account::create(['name' => 'Acc']);
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Board', 'trello_board_id' => 'board_1']);
        $this->integration = Integration::create([
            'account_id' => $this->account->id, 'provider' => 'trello', 'is_enabled' => true,
            'api_key' => 'token', 'settings' => ['trello_api_key' => 'key'],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (preg_match('#/cards/\w+/actions#', $url) === 1) {
                return $this->moveLookupFails
                    ? Http::response(['message' => 'unavailable'], 503)
                    : Http::response([
                        ['id' => 'a2', 'type' => 'updateCard', 'date' => $this->moveAt ?? now()->toIso8601String()],
                        ['id' => 'a1', 'type' => 'updateCard', 'date' => '2026-09-01T09:00:00.000Z'],
                    ]);
            }
            if (str_contains($url, '/boards/board_1/lists')) {
                return Http::response(collect(self::LISTS)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all());
            }
            if (str_contains($url, '/boards/board_2/cards')) {
                return Http::response($this->board2Cards);
            }
            if (str_contains($url, '/boards/board_1/cards')) {
                if ($this->onCardsFetch !== null) {
                    ($this->onCardsFetch)();
                }
                if ($this->cardsResponse !== null) {
                    return $this->cardsResponse;
                }

                return Http::response([...$this->extraCards, [
                    'id' => 'card1', 'name' => 'Header', 'desc' => '', 'idList' => $this->cardList, 'pos' => 1,
                    'due' => null, 'dueComplete' => $this->dueComplete, 'url' => 'https://trello.com/c/1', 'closed' => false, 'labels' => [],
                    'dateLastActivity' => $this->cardActivity ?? now()->toIso8601String(),
                ]]);
            }
            if (preg_match('#/boards/(board_[12])/lists#', $url) === 1) {
                return Http::response(collect(self::LISTS)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all());
            }
            if (preg_match('#/boards/(board_[12])#', $url, $m) === 1) {
                return Http::response(['id' => $m[1], 'name' => 'Board', 'desc' => '', 'url' => 'https://trello.com/b/'.$m[1]]);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_tick_survives_a_sync_with_the_card_unchanged(): void
    {
        $task = $this->tickedInCrm();

        $this->syncAt('2026-09-16 08:05:00');

        $task->refresh();
        $this->assertSame('2026-09-16 08:00:00', $task->finished_at->toDateTimeString());
        $this->assertFalse($task->is_completed);
        $this->assertSame('Doing', $task->list_name);
        $this->assertSame(0, Task::open()->count());
    }

    public function test_a_tick_survives_the_card_moving_to_testing(): void
    {
        $task = $this->tickedInCrm();

        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 09:00:00');

        $task->refresh();
        $this->assertSame('2026-09-16 08:00:00', $task->finished_at->toDateTimeString());
        $this->assertSame('Testing', $task->list_name);
        $this->assertSame(0, Task::open()->count());
    }

    public function test_a_tick_is_dropped_when_the_card_is_sent_back_to_an_active_list(): void
    {
        $task = $this->tickedInCrm();
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 09:00:00');

        $this->cardList = 'l_todo';
        $this->syncAt('2026-09-17 09:00:00');

        $task->refresh();
        $this->assertNull($task->finished_at);
        $this->assertSame('To-Do', $task->list_name);
        $this->assertSame(Task::AGENT_LANE_BACKLOG, $task->agent_lane);
        $this->assertSame([$task->id], Task::open()->pluck('id')->all());
    }

    public function test_a_move_made_before_the_tick_does_not_undo_it(): void
    {
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();

        // The client moves the card back to Doing at 08:10; the owner ticks at 08:20; a comment
        // lands at 08:25, before the next sync.
        $this->cardList = 'l_doing';
        $this->moveAt = '2026-09-16T08:10:00.000Z';
        $this->cardActivity = '2026-09-16T08:25:00.000Z';
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:20:00', 'UTC'));
        app(TaskCompletion::class)->finish($task);

        $this->syncAt('2026-09-16 08:30:00');

        $task->refresh();
        $this->assertSame('2026-09-16 08:20:00', $task->finished_at->toDateTimeString());
        $this->assertSame('Doing', $task->list_name);
    }

    public function test_a_failed_move_lookup_keeps_the_tick(): void
    {
        $task = $this->tickedInCrm();

        $this->cardList = 'l_todo';
        $this->moveLookupFails = true;
        $this->syncAt('2026-09-16 09:00:00');

        $task->refresh();
        $this->assertSame('To-Do', $task->list_name);
        $this->assertSame('2026-09-16 08:00:00', $task->finished_at->toDateTimeString());
        $this->assertSame('2026-09-16 09:00:00', $task->trello_move_pending_at?->toDateTimeString());

        // The list id already matches now, but the question is still open: the next sync asks again.
        $this->moveLookupFails = false;
        $this->moveAt = '2026-09-16T08:45:00.000Z';
        $this->syncAt('2026-09-16 09:05:00');

        $task->refresh();
        $this->assertNull($task->finished_at);
        $this->assertNull($task->trello_move_pending_at);
    }

    public function test_an_answered_move_ends_the_retries_either_way(): void
    {
        $task = $this->tickedInCrm();
        $this->cardList = 'l_todo';
        $this->moveLookupFails = true;
        $this->syncAt('2026-09-16 09:00:00');

        // Trello answers that the move came before the tick: the tick stands and nobody asks again.
        $this->moveLookupFails = false;
        $this->moveAt = '2026-09-16T07:50:00.000Z';
        $this->syncAt('2026-09-16 09:05:00');
        $this->assertNull($task->fresh()->trello_move_pending_at);

        $lookups = fn () => Http::recorded(fn ($request) => str_contains($request->url(), '/actions'))->count();
        $before = $lookups();
        $this->syncAt('2026-09-16 09:10:00');

        $this->assertSame($before, $lookups());
        $this->assertSame('2026-09-16 08:00:00', $task->fresh()->finished_at->toDateTimeString());
    }

    public function test_an_open_question_is_dropped_when_the_card_goes_to_testing(): void
    {
        $task = $this->tickedInCrm();
        $this->cardList = 'l_todo';
        $this->moveLookupFails = true;
        $this->syncAt('2026-09-16 09:00:00');

        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 09:05:00');

        $task->refresh();
        $this->assertNull($task->trello_move_pending_at);
        $this->assertNotNull($task->finished_at);
    }

    public function test_a_move_within_the_clock_skew_of_the_tick_keeps_it(): void
    {
        $task = $this->tickedInCrm();

        $this->cardList = 'l_todo';
        $this->moveAt = '2026-09-16T08:00:45.000Z';
        $this->syncAt('2026-09-16 09:00:00');

        $this->assertNotNull($task->fresh()->finished_at);
    }

    public function test_only_a_finished_card_that_moved_to_an_active_list_costs_a_move_lookup(): void
    {
        $this->tickedInCrm();
        $this->syncAt('2026-09-16 08:30:00');
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 08:40:00');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions'));

        $this->cardList = 'l_todo';
        $this->syncAt('2026-09-16 08:50:00');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/cards/card1/actions') && str_contains(urldecode($request->url()), 'filter=updateCard:idList'));
    }

    public function test_a_row_without_a_recorded_list_just_records_it(): void
    {
        $task = $this->tickedInCrm();
        $task->forceFill(['trello_list_id' => null])->save();

        $this->cardList = 'l_todo';
        $this->syncAt('2026-09-16 09:00:00');

        $task->refresh();
        $this->assertSame('l_todo', $task->trello_list_id);
        $this->assertSame('2026-09-16 08:00:00', $task->finished_at->toDateTimeString());
    }

    public function test_a_tick_made_while_a_sync_runs_is_kept(): void
    {
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();

        // The owner ticks after the sync has read the row and before it writes the card.
        $this->onFirstRead(fn () => app(TaskCompletion::class)->finish(Task::findOrFail($task->id)));
        $this->syncAt('2026-09-16 08:00:00');

        $this->assertSame('2026-09-16 08:00:00', $task->fresh()->finished_at?->toDateTimeString());
    }

    public function test_a_delayed_older_fetch_cannot_complete_a_card_a_newer_sync_made_active(): void
    {
        $this->cardList = 'l_doing';
        $this->cardActivity = '2026-09-16T09:00:00.000Z';
        $this->syncAt('2026-09-16 09:01:00');

        // A sync that fetched at 08:30, when the card sat on Done, writes after the newer one.
        $this->cardList = 'l_done';
        $this->cardActivity = '2026-09-16T08:30:00.000Z';
        $this->syncAt('2026-09-16 09:02:00');

        $task = Task::where('trello_card_id', 'card1')->sole();
        $this->assertSame('Doing', $task->list_name);
        $this->assertFalse($task->is_completed);
        $this->assertNull($task->finished_at);
    }

    public function test_a_delayed_older_fetch_cannot_clear_a_finish_a_newer_sync_completed(): void
    {
        $task = $this->tickedInCrm();
        $this->cardList = 'l_done';
        $this->cardActivity = '2026-09-16T09:00:00.000Z';
        $this->syncAt('2026-09-16 09:01:00');

        $this->cardList = 'l_todo';
        $this->cardActivity = '2026-09-16T08:30:00.000Z';
        $this->syncAt('2026-09-16 09:02:00');

        $task->refresh();
        $this->assertTrue($task->is_completed);
        $this->assertSame('2026-09-16 08:00:00', $task->finished_at->toDateTimeString());
        $this->assertSame(Task::AGENT_LANE_DONE, $task->agent_lane);
    }

    public function test_a_board_already_syncing_is_not_synced_twice_and_frees_itself(): void
    {
        $this->syncAt('2026-09-16 07:55:00');
        Cache::lock('trello-sync:board:board_1', 600)->get();

        try {
            $this->syncAt('2026-09-16 08:00:00');
            $this->fail('A second sync of the same board ran.');
        } catch (TrelloBoardSyncRunning) {
        }

        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->actingAs($user)->post("/projects/{$this->project->id}/sync-trello")->assertSessionHas('error');

        // A crashed run's lock runs out on its own.
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 08:11:00');
        $this->assertSame('Testing', Task::where('trello_card_id', 'card1')->sole()->list_name);
    }

    public function test_a_card_finished_in_trello_gets_its_finish_once(): void
    {
        $this->syncAt('2026-09-16 08:00:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        $this->assertNull($task->finished_at);

        $this->cardList = 'l_done';
        $this->syncAt('2026-09-16 10:00:00');
        $this->syncAt('2026-09-17 10:00:00');
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-18 10:00:00');

        $this->assertSame('2026-09-16 10:00:00', $task->fresh()->finished_at->toDateTimeString());
    }

    public function test_a_mapping_change_that_completes_a_card_gives_it_its_finish_once(): void
    {
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);

        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));
        $this->actingAs($user)->put("/projects/{$this->project->id}/trello-list-mapping", ['mapping' => ['l_test' => 'Done']])->assertRedirect();

        $task->refresh();
        $this->assertTrue($task->is_completed);
        $this->assertSame('2026-09-16 08:00:00', $task->finished_at->toDateTimeString());

        $this->syncAt('2026-09-16 09:00:00');
        $this->assertSame('2026-09-16 08:00:00', $task->fresh()->finished_at->toDateTimeString());
    }

    public function test_a_mapping_change_keeps_a_due_complete_card_completed(): void
    {
        $this->dueComplete = true;
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        $this->assertTrue($task->is_completed);
        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);

        $this->actingAs($user)->put("/projects/{$this->project->id}/trello-list-mapping", ['mapping' => ['l_doing' => 'Testing']])->assertRedirect();

        $task->refresh();
        $this->assertSame('Testing', $task->list_name);
        $this->assertTrue($task->is_completed);
    }

    public function test_a_card_completed_in_trello_keeps_the_owners_timer_running_and_on_its_pick(): void
    {
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->actingAs($user)->post('/day/picks', ['task_id' => $task->id, 'date' => '2026-09-16'])->assertSessionHasNoErrors();
        $timer = $this->actingAs($user)->postJson("/tasks/{$task->id}/time-entries/start")->json('id');

        $this->cardList = 'l_done';
        $this->syncAt('2026-09-16 08:30:00');

        $this->assertTrue($task->fresh()->is_completed);
        $this->assertNull(TimeEntry::findOrFail($timer)->end_time);
        $this->actingAs($user)->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('picks.0.done', true)
                ->where('picks.0.running_id', $timer)
                ->has('looseTimers', 0)
            );
        $this->actingAs($user)->post('/day/close')->assertSessionHasErrors('close');
    }

    public function test_a_card_still_completed_by_its_due_date_keeps_its_finish_when_it_moves(): void
    {
        $this->dueComplete = true;
        $this->cardList = 'l_test';
        $task = $this->tickedInCrm();

        $this->cardList = 'l_doing';
        $this->syncAt('2026-09-16 09:00:00');

        $task->refresh();
        $this->assertTrue($task->is_completed);
        $this->assertSame('2026-09-16 08:00:00', $task->finished_at->toDateTimeString());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions'));
    }

    public function test_a_remap_before_the_first_sync_leaves_an_unknown_completion_alone(): void
    {
        $this->cardList = 'l_doing';
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        // A row from before the due-complete flag was recorded, completed in Trello by its due date.
        $task->forceFill(['trello_due_complete' => null, 'is_completed' => true])->save();
        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);

        $this->actingAs($user)->put("/projects/{$this->project->id}/trello-list-mapping", ['mapping' => ['l_doing' => 'Testing']])->assertRedirect();

        $task->refresh();
        $this->assertSame('Testing', $task->list_name);
        $this->assertTrue($task->is_completed);
        $this->assertNull($task->trello_due_complete);
    }

    public function test_the_migration_marks_every_due_complete_flag_unknown(): void
    {
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        $this->assertFalse($task->trello_due_complete);

        (require database_path('migrations/2026_10_01_140100_make_trello_due_complete_unknown_until_synced.php'))->up();

        $this->assertNull($task->fresh()->trello_due_complete);
    }

    public function test_a_remap_uses_the_list_the_card_is_on_under_the_lock(): void
    {
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 07:55:00');
        $stale = Task::where('trello_card_id', 'card1')->sole();
        $settings = $this->project->fresh()->settings;
        $settings['trello_list_mapping']['l_test'] = 'Done';
        $this->project->update(['settings' => $settings]);

        // A sync moves the card to an active list after the mapping save picked it up.
        Task::whereKey($stale->id)->update(['trello_list_id' => 'l_todo', 'list_name' => 'To-Do']);
        CardFinishReconciler::remap($stale);

        $task = $stale->fresh();
        $this->assertSame('To-Do', $task->list_name);
        $this->assertFalse($task->is_completed);
        $this->assertNull($task->finished_at);
    }

    public function test_a_mapping_save_waits_for_no_sync_and_refuses_while_one_runs(): void
    {
        $this->cardList = 'l_test';
        $this->syncAt('2026-09-16 07:55:00');
        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $lock = Cache::lock('trello-sync:board:board_1', 600);
        $lock->get();

        $this->actingAs($user)->put("/projects/{$this->project->id}/trello-list-mapping", ['mapping' => ['l_test' => 'Done']])
            ->assertSessionHas('error');
        $this->assertSame('Testing', Task::where('trello_card_id', 'card1')->sole()->list_name);
        $this->assertNotSame('Done', $this->project->fresh()->settings['trello_list_mapping']['l_test']);

        $lock->release();
        $this->actingAs($user)->put("/projects/{$this->project->id}/trello-list-mapping", ['mapping' => ['l_test' => 'Done']])
            ->assertSessionHas('success');
        $this->assertSame('Done', Task::where('trello_card_id', 'card1')->sole()->list_name);
    }

    public function test_a_card_deleted_in_trello_archives_its_task_and_comes_back_with_the_card(): void
    {
        $this->extraCards = [$this->card('card2', 'l_doing')];
        $this->syncAt('2026-09-16 08:00:00');
        $gone = Task::where('trello_card_id', 'card2')->sole();

        $this->extraCards = [];
        $this->syncAt('2026-09-16 08:05:00');
        $this->assertSame('2026-09-16 08:05:00', $gone->fresh()->archived_at?->toDateTimeString());
        $this->assertNull(Task::where('trello_card_id', 'card1')->sole()->archived_at);

        $this->extraCards = [$this->card('card2', 'l_doing')];
        $this->syncAt('2026-09-16 08:10:00');
        $this->assertNull($gone->fresh()->archived_at);
    }

    public function test_a_failed_or_partial_card_fetch_archives_nothing(): void
    {
        $this->extraCards = [$this->card('card2', 'l_doing')];
        $this->syncAt('2026-09-16 08:00:00');

        $this->cardsResponse = Http::response(['message' => 'unavailable'], 503);
        try {
            $this->syncAt('2026-09-16 08:05:00');
        } catch (RuntimeException) {
        }

        $this->cardsResponse = Http::response([$this->card('card1', 'l_doing'), ['name' => 'no id']]);
        $this->syncAt('2026-09-16 08:10:00');

        $this->cardsResponse = Http::response(collect(range(1, 1000))->map(fn (int $i) => $this->card("bulk{$i}", 'l_doing'))->all());
        $this->syncAt('2026-09-16 08:15:00');

        $this->assertSame(0, Task::whereIn('trello_card_id', ['card1', 'card2'])->whereNotNull('archived_at')->count());
    }

    public function test_a_card_moved_to_another_board_of_the_same_client_keeps_one_task(): void
    {
        $this->extraCards = [$this->card('card2', 'l_doing')];
        $this->syncAt('2026-09-16 08:00:00');
        $task = Task::where('trello_card_id', 'card2')->sole();
        $other = Project::create(['account_id' => $this->account->id, 'client_id' => $this->project->client_id, 'name' => 'Board 2', 'trello_board_id' => 'board_2']);

        $this->extraCards = [];
        $this->board2Cards = [$this->card('card2', 'l_todo')];
        $this->syncBoard2At('2026-09-16 08:05:00');
        $this->syncAt('2026-09-16 08:05:00');

        $this->assertSame(1, Task::where('trello_card_id', 'card2')->count());
        $task->refresh();
        $this->assertSame($other->id, $task->project_id);
        $this->assertNull($task->archived_at);
    }

    public function test_a_card_moved_to_another_clients_board_leaves_its_task_and_hours_with_the_first_client(): void
    {
        $this->extraCards = [$this->card('card2', 'l_doing')];
        $this->syncAt('2026-09-16 08:00:00');
        $old = Task::where('trello_card_id', 'card2')->sole();
        $old->update(['is_reportable' => true]);
        $clientA = Client::findOrFail($this->project->client_id);
        $entry = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'client_id' => $clientA->id, 'task_id' => $old->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-09-10 09:00:00', 'end_time' => '2026-09-10 11:00:00', 'duration_minutes' => 120,
        ]);
        $clientB = Client::create(['account_id' => $this->account->id, 'name' => 'Other client', 'type' => 'business']);
        $boardB = Project::create(['account_id' => $this->account->id, 'client_id' => $clientB->id, 'name' => 'Board B', 'trello_board_id' => 'board_2']);
        $this->assertSame(2.0, $this->septemberHours($clientA));

        // The card moves from client A's board to client B's.
        $this->extraCards = [];
        $this->board2Cards = [$this->card('card2', 'l_todo')];
        $this->syncBoard2At('2026-09-16 08:05:00');
        $this->syncAt('2026-09-16 08:05:00');

        $old->refresh();
        $new = Task::where('trello_card_id', 'card2')->where('project_id', $boardB->id)->sole();
        $this->assertSame($this->project->id, $old->project_id);
        $this->assertNotNull($old->archived_at);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(0, TimeEntry::where('task_id', $new->id)->count());
        $this->assertSame([$this->project->id, $clientA->id, $old->id], [$entry->fresh()->project_id, $entry->fresh()->client_id, $entry->fresh()->task_id]);
        $this->assertSame(2.0, $this->septemberHours($clientA));
        $this->assertSame(0.0, $this->septemberHours($clientB));

        // The card goes back to A's board: A's own task comes back, no third task appears.
        $this->extraCards = [$this->card('card2', 'l_doing')];
        $this->board2Cards = [];
        $this->syncAt('2026-09-16 08:10:00');
        $this->syncBoard2At('2026-09-16 08:10:00');

        $this->assertSame(2, Task::where('trello_card_id', 'card2')->count());
        $this->assertNull($old->fresh()->archived_at);
        $this->assertNotNull($new->fresh()->archived_at);
        $this->assertSame(2.0, $this->septemberHours($clientA));
    }

    public function test_a_report_counts_an_entry_for_the_client_it_was_logged_for_wherever_its_task_lives_now(): void
    {
        $this->syncAt('2026-09-16 08:00:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        $task->update(['is_reportable' => true]);
        $clientA = Client::findOrFail($this->project->client_id);
        TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'client_id' => $clientA->id, 'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-09-10 09:00:00', 'end_time' => '2026-09-10 10:30:00', 'duration_minutes' => 90,
        ]);
        $clientB = Client::create(['account_id' => $this->account->id, 'name' => 'Other client', 'type' => 'business']);
        $task->update(['project_id' => Project::create(['account_id' => $this->account->id, 'client_id' => $clientB->id, 'name' => 'Elsewhere'])->id]);

        $this->assertSame(1.5, $this->septemberHours($clientA));
        $this->assertSame(0.0, $this->septemberHours($clientB));

        $task->update(['is_reportable' => false]);
        $this->assertSame(0.0, $this->septemberHours($clientA));
    }

    public function test_move_lookups_per_run_are_capped_and_the_rest_wait_for_the_next_run(): void
    {
        $ids = collect(range(1, 12))->map(fn (int $i) => "moved{$i}");
        $this->extraCards = $ids->map(fn (string $id) => $this->card($id, 'l_test'))->all();
        $this->syncAt('2026-09-16 07:55:00');
        Task::whereIn('trello_card_id', $ids)->update(['finished_at' => '2026-09-16 07:56:00']);

        $this->cardActivity = '2026-09-16T08:30:00.000Z';
        $this->extraCards = $ids->map(fn (string $id) => $this->card($id, 'l_todo'))->all();
        $this->moveAt = '2026-09-16T08:30:00.000Z';
        $lookups = fn () => Http::recorded(fn ($request) => str_contains($request->url(), '/actions'))->count();

        $this->syncAt('2026-09-16 09:00:00');
        $this->assertSame(10, $lookups());
        $this->assertSame(2, Task::whereIn('trello_card_id', $ids)->whereNotNull('trello_move_pending_at')->count());
        $this->assertSame(2, Task::whereIn('trello_card_id', $ids)->whereNotNull('finished_at')->count());

        $this->syncAt('2026-09-16 09:05:00');
        $this->assertSame(12, $lookups());
        $this->assertSame(0, Task::whereIn('trello_card_id', $ids)->whereNotNull('finished_at')->count());
        $this->assertSame(0, Task::whereIn('trello_card_id', $ids)->whereNotNull('trello_move_pending_at')->count());
    }

    public function test_a_sync_past_its_deadline_stops_cleanly_and_archives_nothing(): void
    {
        $this->extraCards = [$this->card('card2', 'l_doing')];
        $this->syncAt('2026-09-16 08:00:00');

        $this->extraCards = [];
        $this->cardList = 'l_test';
        $this->onCardsFetch = fn () => Carbon::setTestNow(now()->addSeconds(481));
        $this->syncAt('2026-09-16 08:05:00');

        $this->assertNull(Task::where('trello_card_id', 'card2')->sole()->archived_at);
        $this->assertSame('Doing', Task::where('trello_card_id', 'card1')->sole()->list_name);
    }

    public function test_a_card_imported_already_done_gets_no_invented_finish_date(): void
    {
        $this->cardList = 'l_done';
        $this->syncAt('2026-09-16 08:00:00');

        $task = Task::where('trello_card_id', 'card1')->sole();
        $this->assertTrue($task->is_completed);
        $this->assertNull($task->finished_at);
    }

    private function tickedInCrm(): Task
    {
        $this->syncAt('2026-09-16 07:55:00');
        $task = Task::where('trello_card_id', 'card1')->sole();
        $task->update(['cli' => 'claude', 'agent_lane' => Task::AGENT_LANE_IN_PROGRESS]);

        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));
        app(TaskCompletion::class)->finish($task);

        return $task;
    }

    /** Runs $tick once, the first time the sync loads the card's row. */
    private function onFirstRead(Closure $tick): void
    {
        $fired = false;
        Task::retrieved(function (Task $task) use (&$fired, $tick): void {
            if (! $fired && $task->trello_card_id === 'card1') {
                $fired = true;
                $tick();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $id, string $list): array
    {
        return [
            'id' => $id, 'name' => "Card {$id}", 'desc' => '', 'idList' => $list, 'pos' => 1, 'due' => null, 'dueComplete' => false,
            'url' => "https://trello.com/c/{$id}", 'closed' => false, 'labels' => [], 'dateLastActivity' => $this->cardActivity ?? now()->toIso8601String(),
        ];
    }

    private function septemberHours(Client $client): float
    {
        return app(ReportDataAggregator::class)
            ->aggregate($client, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 'month')
            ->actualHours;
    }

    private function syncBoard2At(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        (new TrelloService($this->integration))->syncBoard('board_2', $this->account->id);
    }

    private function syncAt(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        (new TrelloService($this->integration))->syncBoard('board_1', $this->account->id);
    }
}

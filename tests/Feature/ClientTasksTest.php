<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\ClientTasksQuery;
use App\Support\LocalCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ClientTasksTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = $this->account->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        // The lanes are the retainer layout; an order client reads one list (ClientOrderLayoutTest).
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 10, 'effective_from' => '2026-01-01']);
    }

    public function test_only_the_tasks_tab_computes_the_lanes(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/zadania")
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->where('tasks.expanded', null)
                ->where('tasks.lanes.0.key', 'doing')
                ->where('tasks.lanes.1.key', 'testing')
                ->where('tasks.lanes.2.key', 'todo')
                ->missing('overview')
            );

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (Assert $page) => $page->missing('tasks'));
    }

    public function test_each_lane_sends_five_rows_and_counts_the_rest(): void
    {
        foreach (range(1, 12) as $i) {
            $this->task("Doing {$i}", 'Doing', ['position' => $i]);
        }
        foreach (range(1, 6) as $i) {
            $this->task("Testing {$i}", 'Testing', ['position' => $i]);
        }
        $this->task('Only to-do', 'To-Do');

        $lanes = $this->lanes();

        $this->assertSame([12, 6, 1], array_column($lanes, 'count'));
        $this->assertSame([5, 5, 1], array_map(fn (array $lane) => count($lane['rows']), $lanes));
        $this->assertSame(['Doing 1', 'Doing 2', 'Doing 3', 'Doing 4', 'Doing 5'], array_column($lanes[0]['rows'], 'name'));
    }

    public function test_the_expanded_lane_sends_every_row_and_the_others_stay_at_five(): void
    {
        foreach (range(1, 7) as $i) {
            $this->task("Doing {$i}", 'Doing', ['position' => $i]);
            $this->task("To-do {$i}", 'To-Do', ['position' => $i]);
        }

        $tasks = app(ClientTasksQuery::class)->props($this->client, 'todo')['tasks'];

        $this->assertSame('todo', $tasks['expanded']);
        $this->assertCount(5, $tasks['lanes'][0]['rows']);
        $this->assertCount(7, $tasks['lanes'][2]['rows']);
    }

    public function test_an_unknown_or_malformed_expand_is_ignored(): void
    {
        foreach (range(1, 7) as $i) {
            $this->task("Doing {$i}", 'Doing');
        }

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/zadania?wszystkie=Backlog")
            ->assertInertia(fn (Assert $page) => $page->where('tasks.expanded', null)->has('tasks.lanes.0.rows', 5));

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/zadania?wszystkie[]=doing")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tasks.expanded', null));

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/zadania?wszystkie=doing")
            ->assertInertia(fn (Assert $page) => $page->where('tasks.expanded', 'doing')->has('tasks.lanes.0.rows', 7));
    }

    public function test_lanes_hold_only_open_tasks_on_their_list(): void
    {
        $this->task('Open', 'Doing');
        $this->task('Ticked', 'Doing', ['finished_at' => now()]);
        $this->task('Completed', 'Doing', ['is_completed' => true]);
        $this->task('Archived', 'Doing', ['archived_at' => now()]);
        $this->task('On Done', 'Done');
        $this->task('In backlog', 'Backlog');
        $archivedProject = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Old', 'archived_at' => now()]);
        Task::create(['project_id' => $archivedProject->id, 'name' => 'Old project', 'source' => 'manual', 'list_name' => 'Doing']);

        $lanes = $this->lanes();

        $this->assertSame(['Open'], array_column($lanes[0]['rows'], 'name'));
        $this->assertSame([1, 0, 0], array_column($lanes, 'count'));
    }

    public function test_a_task_without_a_list_waits_in_to_do_and_only_backlog_stays_out(): void
    {
        $this->task('Listed', 'To-Do', ['position' => 1]);
        Task::create(['project_id' => $this->project->id, 'name' => 'Unlisted', 'source' => 'manual', 'position' => 2]);
        Task::query()->where('name', 'Unlisted')->update(['list_name' => null]);
        $this->task('Doing one', 'Doing');
        $this->task('Testing one', 'Testing');
        $this->task('Backlog one', 'Backlog');
        $this->task('Backlog two', 'Backlog');

        $lanes = $this->lanes();

        $this->assertSame(['Listed', 'Unlisted'], array_column($lanes[2]['rows'], 'name'));
        $this->assertSame([1, 1, 2], array_column($lanes, 'count'));
    }

    public function test_the_tab_badge_counts_exactly_what_the_lanes_show_so_backlog_and_other_lists_stay_out(): void
    {
        $this->task('Listed', 'To-Do');
        Task::create(['project_id' => $this->project->id, 'name' => 'Unlisted', 'source' => 'manual']);
        Task::query()->where('name', 'Unlisted')->update(['list_name' => null]);
        $this->task('Doing one', 'Doing');
        $this->task('Testing one', 'Testing');
        $this->task('Backlog one', 'Backlog');
        $this->task('Backlog two', 'Backlog');
        $this->task('Waiting on the client', 'Klient czeka');
        $this->task('Ticked', 'Doing', ['finished_at' => now()]);

        foreach (['zadania', 'przeglad', 'dane'] as $tab) {
            $props = $this->actingAs($this->user)->get("/clients/{$this->client->id}/{$tab}")->viewData('page')['props'];
            $this->assertSame(4, $props['client']['open_tasks_count'], $tab);
            if ($tab === 'zadania') {
                $this->assertSame(4, array_sum(array_column($props['tasks']['lanes'], 'count')));
            }
        }
    }

    public function test_a_due_date_is_the_display_timezones_calendar_day(): void
    {
        $this->task('Written date', 'Doing', ['due_date' => '2026-10-30', 'position' => 1]);
        $this->task('Card due late evening UTC', 'Doing', ['due_date' => '2026-10-09 22:30:00', 'trello_card_id' => 'card-1', 'source' => 'trello', 'position' => 2]);
        $this->task('No date', 'Doing', ['position' => 3]);

        $rows = $this->lanes()[0]['rows'];

        $this->assertSame(['2026-10-30', '2026-10-10', null], array_column($rows, 'due'));
    }

    public function test_the_owners_running_timer_is_stoppable_and_an_agents_shows_as_live(): void
    {
        $mine = $this->task('Mine running', 'Doing', ['position' => 1]);
        $agents = $this->task('Agent running', 'Doing', ['position' => 2]);
        $stopped = $this->task('Stopped earlier', 'Doing', ['position' => 3]);

        $entry = TimeEntry::startFor($mine);
        TimeEntry::startFor($agents)->forceFill(['actor_via' => 'cli'])->save();
        TimeEntry::startFor($stopped)->stopNow();

        $rows = collect($this->lanes()[0]['rows'])->keyBy('name');

        $this->assertSame($entry->id, $rows['Mine running']['running_id']);
        $this->assertFalse($rows['Mine running']['agent_live']);
        $this->assertNull($rows['Agent running']['running_id']);
        $this->assertTrue($rows['Agent running']['agent_live']);
        $this->assertNull($rows['Stopped earlier']['running_id']);
        $this->assertFalse($rows['Stopped earlier']['agent_live']);
    }

    public function test_the_owners_running_timer_carries_its_start_for_the_phone_elapsed_time_and_an_agents_does_not(): void
    {
        $mine = $this->task('Mine running', 'Doing', ['position' => 1]);
        $agents = $this->task('Agent running', 'Doing', ['position' => 2]);
        $idle = $this->task('Idle', 'Doing', ['position' => 3]);

        $this->travelTo(Carbon::parse('2026-10-05 09:18:00', 'UTC'));
        TimeEntry::startFor($mine);
        TimeEntry::startFor($agents)->forceFill(['actor_via' => 'cli'])->save();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));

        $rows = collect($this->lanes()[0]['rows'])->keyBy('name');

        $this->assertSame('2026-10-05T09:18:00+00:00', $rows['Mine running']['running_since']);
        $this->assertNull($rows['Agent running']['running_since']);
        $this->assertNull($rows['Idle']['running_since']);
        $this->assertSame($idle->id, $rows['Idle']['task_id']);
    }

    public function test_na_dzis_on_a_row_picks_the_task_for_today_and_the_row_then_says_picked(): void
    {
        $task = $this->task('Pick me', 'Doing');

        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id])->assertSessionHasNoErrors();

        $this->assertSame(LocalCalendar::today()->toDateString(), DayPick::sole()->date->toDateString());
        $this->assertTrue($this->lanes()[0]['rows'][0]['picked']);
    }

    public function test_the_tasks_tab_sends_the_same_props_and_queries_whatever_the_phone_asks_for(): void
    {
        $this->task('Doing 1', 'Doing');
        $url = "/clients/{$this->client->id}/zadania";
        $this->actingAs($this->user)->get($url)->assertOk();

        [$plain, $plainQueries] = $this->measured($url);
        [$phone, $phoneQueries] = $this->measured($url, ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148']);

        $this->assertSame($plain, $phone);
        $this->assertSame($plainQueries, $phoneQueries);
    }

    public function test_with_the_owner_and_an_agent_on_one_task_stop_targets_the_owners_timer(): void
    {
        $task = $this->task('Shared', 'Doing');
        TimeEntry::startFor($task)->forceFill(['actor_via' => 'mcp'])->save();
        $mine = TimeEntry::startFor($task);

        $row = $this->lanes()[0]['rows'][0];

        $this->assertSame($mine->id, $row['running_id']);
        $this->assertTrue($row['agent_live']);

        $this->actingAs($this->user)->postJson("/time-entries/{$row['running_id']}/stop")->assertOk();

        $row = $this->lanes()[0]['rows'][0];
        $this->assertNull($row['running_id']);
        $this->assertTrue($row['agent_live']);
        $this->assertSame(1, TimeEntry::whereNull('end_time')->whereNotNull('actor_via')->count());
    }

    public function test_ticking_through_the_day_endpoint_stops_the_timer_and_leaves_the_lane(): void
    {
        $task = $this->task('Header', 'Doing');
        $entry = TimeEntry::startFor($task);

        $this->actingAs($this->user)
            ->from("/clients/{$this->client->id}/zadania")
            ->post("/day/tasks/{$task->id}/complete")
            ->assertRedirect("/clients/{$this->client->id}/zadania");

        $this->assertNotNull($entry->fresh()->end_time);
        $this->assertSame('Done', $task->fresh()->list_name);
        $this->assertSame(0, $this->lanes()[0]['count']);
    }

    public function test_start_and_stop_go_through_the_timer_path_and_one_request_id_opens_one_timer(): void
    {
        $task = $this->task('Header', 'Doing');
        $id = '6f1d2c3b-4a59-4e6f-8a7b-9c0d1e2f3a4b';

        $first = $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start", ['request_id' => $id])->assertCreated()->json('id');
        $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start", ['request_id' => $id])->assertOk()->assertJsonPath('id', $first);

        $this->assertSame(1, TimeEntry::where('task_id', $task->id)->count());
        $this->assertSame($first, $this->lanes()[0]['rows'][0]['running_id']);

        $this->actingAs($this->user)->postJson("/time-entries/{$first}/stop")->assertOk();

        $this->assertNull($this->lanes()[0]['rows'][0]['running_id']);
    }

    public function test_a_trello_cards_tick_keeps_the_boards_list_and_title(): void
    {
        $card = $this->task('Card title from Trello', 'Testing', ['trello_card_id' => 'card-9', 'source' => 'trello']);

        $this->assertSame('Card title from Trello', $this->lanes()[1]['rows'][0]['name']);

        $this->actingAs($this->user)->post("/day/tasks/{$card->id}/complete")->assertRedirect();

        $card->refresh();
        $this->assertNotNull($card->finished_at);
        $this->assertSame('Testing', $card->list_name);
        $this->assertSame('Card title from Trello', $card->name);
        $this->assertFalse((bool) $card->is_completed);
        $this->assertSame(0, $this->lanes()[1]['count']);
    }

    public function test_repository_paths_come_from_the_clients_live_projects(): void
    {
        Repository::create(['project_id' => $this->project->id, 'name' => 'site', 'local_path' => '/Users/test/Sites/site', 'provider' => 'github']);
        Repository::create(['project_id' => $this->project->id, 'name' => 'twin', 'local_path' => '/Users/test/Sites/site', 'provider' => 'github']);
        Repository::create(['project_id' => $this->project->id, 'name' => 'remote only', 'local_path' => null, 'remote_url' => 'git@example.test:x.git', 'provider' => 'github']);
        $archived = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Old', 'archived_at' => now()]);
        Repository::create(['project_id' => $archived->id, 'name' => 'old', 'local_path' => '/Users/test/Sites/old', 'provider' => 'github']);

        $tasks = app(ClientTasksQuery::class)->props($this->client)['tasks'];

        $this->assertSame([['path' => '/Users/test/Sites/site', 'project' => 'Site']], $tasks['repositories']);
        $this->assertSame(['General', 'Site'], array_column($tasks['projects'], 'name'));
    }

    public function test_another_accounts_projects_tasks_and_repositories_never_appear(): void
    {
        $other = Account::create(['name' => 'Other']);
        // A row pointing at this client from another account must still stay out.
        $foreign = Project::create(['account_id' => $other->id, 'client_id' => $this->client->id, 'name' => 'Theirs']);
        Task::create(['project_id' => $foreign->id, 'name' => 'Their task', 'source' => 'manual', 'list_name' => 'Doing']);
        Repository::create(['project_id' => $foreign->id, 'name' => 'theirs', 'local_path' => '/home/demo/Sites/theirs', 'provider' => 'github']);
        $this->task('Our task', 'Doing');

        $tasks = app(ClientTasksQuery::class)->props($this->client)['tasks'];

        $this->assertSame(['Our task'], array_column($tasks['lanes'][0]['rows'], 'name'));
        $this->assertSame(1, $tasks['lanes'][0]['count']);
        $this->assertSame([], $tasks['repositories']);
        $this->assertSame(['General', 'Site'], array_column($tasks['projects'], 'name'));
    }

    public function test_another_accounts_user_cannot_open_tick_or_time_this_clients_tasks(): void
    {
        $task = $this->task('Header', 'Doing');
        $entry = TimeEntry::startFor($task);
        $other = Account::create(['name' => 'Other']);
        $stranger = User::factory()->create(['account_id' => $other->id, 'owner' => true]);

        $this->actingAs($stranger)->get("/clients/{$this->client->id}/zadania")->assertNotFound();
        $this->actingAs($stranger)->post("/day/tasks/{$task->id}/complete")->assertNotFound();
        $this->actingAs($stranger)->delete("/day/tasks/{$task->id}/complete")->assertNotFound();
        $this->actingAs($stranger)->postJson("/tasks/{$task->id}/time-entries/start", ['request_id' => 'abababab-1111-4111-8111-abababababab'])->assertNotFound();
        $this->actingAs($stranger)->postJson("/time-entries/{$entry->id}/stop")->assertForbidden();

        $this->assertNull($task->fresh()->finished_at);
        $this->assertNull($entry->fresh()->end_time);
        $this->assertSame(1, TimeEntry::count());
    }

    /**
     * @return list<array{key: string, count: int, rows: list<array<string, mixed>>}>
     */
    /**
     * @param  array<string, string>  $headers
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function measured(string $url, array $headers = []): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $props = $this->actingAs($this->user)->withHeaders($headers)->get($url)->assertOk()->viewData('page')['props'];
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        unset($props['errors'], $props['auth']);

        return [$props, $queries];
    }

    private function lanes(): array
    {
        return app(ClientTasksQuery::class)->props($this->client->fresh())['tasks']['lanes'];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function task(string $name, string $list, array $extra = []): Task
    {
        return Task::create(['project_id' => $this->project->id, 'name' => $name, 'source' => 'manual', 'list_name' => $list, ...$extra]);
    }
}

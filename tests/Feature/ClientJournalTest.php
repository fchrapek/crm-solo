<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\ClientLifecycleEvent;
use App\Models\ClientRetainer;
use App\Models\ClientView;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Clients\ClientJournal;
use App\Services\Clients\ClientJournalQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

final class ClientJournalTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-01-10 10:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true, 'first_name' => 'Filip', 'last_name' => 'Chrapek']);
        $this->client = $this->account->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        ClientLifecycleEvent::query()->delete();

        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'UTC'));
        $this->seenAt('2026-09-28 08:00:00');
    }

    public function test_only_the_journal_tab_computes_the_journal(): void
    {
        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/dziennik")
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->has('journal.summary')
                ->has('journal_work.rows')
                ->has('journal_notes.rows')
                ->has('journal.stage')
                ->has('journal.projects')
            );

        $this->actingAs($this->user)
            ->get("/clients/{$this->client->id}/przeglad")
            ->assertInertia(fn (Assert $page) => $page->missing('journal')->missing('journal_work')->missing('journal_notes'));
    }

    public function test_the_summary_counts_the_rows_since_the_last_visit_and_the_owners_time(): void
    {
        $this->entry('2026-09-28 07:00:00', 600);
        $this->entry('2026-09-29 09:00:00', 195);
        $this->entry('2026-09-30 09:00:00', 90);
        $this->entry('2026-10-01 08:00:00', 40, session: 'run-1');
        $this->entry('2026-10-01 09:00:00', 20, session: 'run-1');
        $this->event('2026-10-01 09:30:00', session: 'run-1');
        $this->event('2026-10-02 09:00:00');

        $journal = $this->journal();

        $this->assertSame('2026-09-28', $journal['since']);
        $this->assertSame(['rows' => 4, 'notes' => 2, 'field_changes' => 0, 'sms' => 0, 'person_minutes' => 285], $journal['summary']);
    }

    public function test_an_agent_session_is_one_work_row_with_the_writes_that_target_its_own_rows(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Deploy na prod', 'list_name' => 'Doing']);
        $first = $this->entry('2026-10-01 08:00:00', 40, session: 'run-1', task: $task);
        $second = $this->entry('2026-10-01 09:00:00', 20, session: 'run-1', task: $task, description: 'H1 w wersjach EN i DE');
        $note = $this->event('2026-10-01 09:30:00', session: 'run-1');
        $this->audit('run-1', 'time_entries', $first->id);
        $this->audit('run-1', 'time_entries', $second->id, 'updated');
        $this->audit('run-1', 'client_lifecycle_events', $note->id);
        $this->audit('run-1', 'tasks', $task->id, 'updated');
        $this->audit('run-2', 'time_entries', $first->id);
        $other = Account::create(['name' => 'Other']);
        AgentAuditEvent::create(['account_id' => $other->id, 'actor' => 'user:x', 'via' => 'cli', 'session_id' => 'run-1', 'verb' => 'time:log', 'action' => 'created', 'target_type' => 'time_entries', 'target_id' => $first->id]);
        $this->entry('2026-09-30 09:00:00', 90);

        $rows = $this->journal()['work']['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame([
            'key' => 'session:run-1',
            'kind' => 'session',
            'date' => '2026-10-01',
            'title' => 'Deploy na prod',
            'subtitle' => 'H1 w wersjach EN i DE',
            'via' => 'cli',
            'minutes' => 60,
            'running' => false,
            'writes' => 3,
            'is_new' => true,
        ], $rows[0]);
        $this->assertSame(['entry', null, null], [$rows[1]['kind'], $rows[1]['via'], $rows[1]['writes']]);
    }

    public function test_sessions_differing_only_in_case_stay_two_rows_with_their_own_members(): void
    {
        $upper = [$this->entry('2026-10-01 08:00:00', 30, session: 'Run-A'), $this->event('2026-10-01 08:30:00', session: 'Run-A')];
        $lower = [$this->entry('2026-10-01 09:00:00', 20, session: 'run-a'), $this->event('2026-10-01 09:30:00', session: 'run-a')];
        $this->audit('Run-A', 'time_entries', $upper[0]->id);
        $this->audit('run-a', 'time_entries', $lower[0]->id);
        $this->audit('run-a', 'client_lifecycle_events', $lower[1]->id);

        $journal = $this->journal();
        $rows = collect($journal['work']['rows'])->keyBy('key');

        $this->assertSame(['session:run-a', 'session:Run-A'], array_column($journal['work']['rows'], 'key'));
        $this->assertSame([20, 1 + 1], [$rows['session:run-a']['minutes'], $rows['session:run-a']['writes']]);
        $this->assertSame([30, 1], [$rows['session:Run-A']['minutes'], $rows['session:Run-A']['writes']]);
        $this->assertSame(['rows' => 2, 'notes' => 2], array_intersect_key($journal['summary'], array_flip(['rows', 'notes'])));

        $members = app(ClientJournal::class)->rowsSince($this->client, Carbon::parse('2026-09-28 00:00:00', 'UTC'))->keyBy('key');
        $this->assertSame([[$lower[0]->id], [$lower[1]->id]], [$members['session:run-a']['entry_ids'], $members['session:run-a']['event_ids']]);
        $this->assertSame([[$upper[0]->id], [$upper[1]->id]], [$members['session:Run-A']['entry_ids'], $members['session:Run-A']['event_ids']]);

        $this->seenAt('2026-09-28 08:00:00');
        $this->assertSame(2, $this->actingAs($this->user)->get("/clients/{$this->client->id}/przeglad")->viewData('page')['props']['overview']['journal']['count']);
    }

    public function test_on_mariadb_session_grouping_compares_ids_byte_for_byte_in_both_arms(): void
    {
        $client = $this->client;
        config(['database.default' => 'mariadb']);
        DB::connection('mariadb')->setPdo(new PDO('sqlite::memory:'))->setReadPdo(new PDO('sqlite::memory:'));

        try {
            $queries = DB::connection('mariadb')->pretend(function () use ($client): void {
                app(ClientJournal::class)->workPage($client, null, 10);
                app(ClientJournal::class)->rowsSince($client, Carbon::parse('2026-09-28 00:00:00', 'UTC'));
            });
        } finally {
            config(['database.default' => 'sqlite']);
        }

        $grouped = collect($queries)->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'group by'));
        $this->assertNotEmpty($grouped);
        foreach ($grouped as $sql) {
            $this->assertSame(2, mb_substr_count($sql, 'CAST(actor_session_id AS BINARY)'), $sql);
        }
    }

    public function test_the_actor_follows_actor_via_with_or_without_a_session(): void
    {
        $agent = $this->entry('2026-10-01 08:00:00', 30);
        $agent->forceFill(['actor_via' => 'cli-remote'])->save();
        $this->entry('2026-10-01 07:00:00', 30);
        $this->event('2026-10-01 09:00:00')->forceFill(['actor_via' => 'mcp'])->save();

        $journal = $this->journal();

        $this->assertSame(['cli-remote', null], array_column($journal['work']['rows'], 'via'));
        $this->assertSame(['entry', 'entry'], array_column($journal['work']['rows'], 'kind'));
        $this->assertSame('mcp', $journal['notes']['rows'][0]['via']);
    }

    public function test_a_visit_earlier_today_says_since_today(): void
    {
        $this->seenAt('2026-10-04 22:30:00');
        $journal = $this->journal();
        $this->assertSame(['2026-10-05', true], [$journal['since'], $journal['since_today']]);

        $this->seenAt('2026-10-04 21:30:00');
        $journal = $this->journal();
        $this->assertSame(['2026-10-04', false], [$journal['since'], $journal['since_today']]);
    }

    public function test_work_rows_are_new_from_the_exact_instant_of_the_last_visit_and_notes_carry_no_flag(): void
    {
        $this->seenAt('2026-10-01 12:00:00');
        $this->entry('2026-10-01 12:00:00', 30);
        $this->entry('2026-10-01 11:59:00', 30);
        $this->event('2026-10-01 12:30:00');
        $this->event('2026-10-01 11:00:00');

        $journal = $this->journal();

        $this->assertSame(2, $journal['summary']['rows']);
        $this->assertSame(1, $journal['summary']['notes']);
        $this->assertSame([true, false], array_column($journal['work']['rows'], 'is_new'));
        $this->assertArrayNotHasKey('is_new', $journal['notes']['rows'][0]);
    }

    public function test_every_work_row_on_every_page_is_new_exactly_when_the_summary_counts_it(): void
    {
        foreach (range(1, ClientJournalQuery::FIRST_PAGE_MAX + 10) as $minute) {
            $this->entry('2026-10-01 08:00:00', 1, minuteOffset: $minute);
        }
        foreach (range(1, 12) as $day) {
            $this->entry(sprintf('2026-09-%02d 09:00:00', 27 - $day), 30);
        }
        $this->entry('2026-09-28 08:00:00', 15);
        $this->entry('2026-09-28 07:59:59', 15);
        $this->entry('2026-09-27 12:00:00', 40, session: 'run-span');
        $this->entry('2026-10-02 09:00:00', 20, session: 'run-span');
        $this->entry('2026-09-27 10:00:00', 30, session: 'run-old');
        $this->event('2026-09-27 10:30:00', session: 'run-old');
        $this->event('2026-10-03 09:00:00', session: 'run-notes');
        $this->event('2026-10-02 10:00:00');

        $fresh = app(ClientJournal::class)->rowsSince($this->client, Carbon::parse('2026-09-28 08:00:00', 'UTC'))->pluck('key')->all();
        $rows = [];
        $page = $this->journal()['work'];
        $pages = 1;
        while ($page['next'] !== null && $pages < 20) {
            $rows = [...$rows, ...$page['rows']];
            $page = $this->partial('journal_work', ['praca' => $page['next']]);
            $pages++;
        }
        $rows = [...$rows, ...$page['rows']];

        $this->assertGreaterThan(2, $pages);
        $this->assertCount(ClientJournalQuery::FIRST_PAGE_MAX + 10 + 12 + 2 + 3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(in_array($row['key'], $fresh, true), $row['is_new'], $row['key']);
        }
        $this->assertSame(ClientJournalQuery::FIRST_PAGE_MAX + 10 + 1 + 2, collect($rows)->where('is_new', true)->count());
        $this->assertSame([true, false], [collect($rows)->firstWhere('key', 'session:run-span')['is_new'], collect($rows)->firstWhere('key', 'session:run-old')['is_new']]);
    }

    public function test_the_first_page_holds_every_new_row_up_to_its_cap(): void
    {
        foreach (range(1, 12) as $day) {
            $this->entry(sprintf('2026-09-%02d 09:00:00', 28 - $day), 30);
        }
        foreach (range(0, 6) as $hour) {
            $this->entry(sprintf('2026-10-01 %02d:00:00', 8 + $hour), 30);
        }
        $this->assertSame(10, count($this->journal()['work']['rows']));

        foreach (range(0, 6) as $hour) {
            $this->entry(sprintf('2026-10-02 %02d:00:00', 8 + $hour), 30);
        }
        $this->seenAt('2026-09-28 08:00:00');
        $this->assertSame(14, count($this->journal()['work']['rows']));

        foreach (range(1, 60) as $minute) {
            $this->entry('2026-10-03 08:00:00', 1, minuteOffset: $minute);
        }
        $this->seenAt('2026-09-28 08:00:00');
        $this->assertSame(ClientJournalQuery::FIRST_PAGE_MAX, count($this->journal()['work']['rows']));
    }

    public function test_starsze_walks_the_whole_work_history_a_page_at_a_time_and_ends(): void
    {
        foreach (range(0, 519) as $n) {
            $this->entry(Carbon::parse('2026-01-01 08:00:00', 'UTC')->addMinutes($n)->format('Y-m-d H:i:s'), 5);
        }
        $this->entry('2026-01-01 07:00:00', 5, session: 'old-run');
        $this->entry('2025-12-31 07:00:00', 5, session: 'old-run');

        $keys = [];
        $requests = 0;
        $page = $this->journal()['work'];
        while (true) {
            $this->assertLessThanOrEqual(ClientJournalQuery::WORK_PAGE, count($page['rows']));
            $keys = [...$keys, ...array_column($page['rows'], 'key')];
            if ($page['next'] === null || ++$requests > 100) {
                break;
            }
            $page = $this->partial('journal_work', ['praca' => $page['next']]);
        }

        $this->assertCount(521, $keys);
        $this->assertCount(521, array_unique($keys));
        $this->assertSame('session:old-run', end($keys));
        $this->assertNull($page['next']);
    }

    public function test_rows_sharing_an_instant_never_split_across_pages(): void
    {
        foreach (range(1, 8) as $n) {
            $this->entry('2026-09-20 09:0'.$n.':00', 5);
        }
        foreach (range(1, 6) as $n) {
            $this->entry('2026-09-10 09:00:00', 5);
        }
        $this->entry('2026-09-01 09:00:00', 5);

        $first = $this->journal()['work'];
        $second = $this->partial('journal_work', ['praca' => $first['next']]);

        $this->assertCount(14, $first['rows']);
        $this->assertSame([], array_values(array_intersect(array_column($first['rows'], 'key'), array_column($second['rows'], 'key'))));
        $this->assertCount(1, $second['rows']);
        $this->assertNull($second['next']);
    }

    public function test_starsze_reloads_only_its_own_list_and_the_client_appends_it(): void
    {
        foreach (range(1, 12) as $n) {
            $this->entry(sprintf('2026-09-%02d 09:00:00', $n), 5);
        }
        $next = $this->journal()['work']['next'];

        $response = $this->inertiaPartial('journal_work', ['praca' => $next]);

        $this->assertArrayHasKey('journal_work', $response->json('props'));
        $this->assertArrayNotHasKey('journal', $response->json('props'));
        $this->assertArrayNotHasKey('journal_notes', $response->json('props'));
        $this->assertContains('journal_work.rows', $response->json('mergeProps'));
        $this->assertContains('journal_work.rows.key', $response->json('matchPropsOn'));
        $this->assertSame(0, ClientView::query()->where('viewed_at', '>', '2026-10-05 10:00:00')->count());
    }

    public function test_notes_show_stage_changes_authors_and_page_by_cursor(): void
    {
        $this->client->update(['lifecycle_stage' => 'active']);
        ClientLifecycleEvent::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'user_id' => null,
            'from_stage' => 'prospect', 'to_stage' => 'active', 'note' => null, 'created_at' => Carbon::parse('2026-06-13 08:00:00', 'UTC'),
        ]);
        foreach (range(1, 6) as $day) {
            $this->event(sprintf('2026-09-%02d 09:00:00', $day));
        }
        $this->event('2026-09-02 09:00:00');

        $first = $this->journal()['notes'];

        $this->assertCount(6, $first['rows'], 'the second entry of 2 September rides along: a page never splits a second');
        $this->assertNotNull($first['next']);
        $this->assertSame(['date' => '2026-09-06', 'author' => 'Filip Chrapek', 'stage_change' => false], array_intersect_key($first['rows'][0], array_flip(['date', 'author', 'stage_change'])));

        $rest = $this->partial('journal_notes', ['wpisy' => $first['next']]);
        $this->assertCount(2, $rest['rows']);
        $this->assertNull($rest['next']);
        $ids = [...array_column($first['rows'], 'id'), ...array_column($rest['rows'], 'id')];
        $this->assertCount(8, array_unique($ids));
        $this->assertSame(['author' => null, 'from_stage' => 'prospect', 'to_stage' => 'active', 'stage_change' => true], array_intersect_key(end($rest['rows']), array_flip(['from_stage', 'to_stage', 'stage_change', 'author'])));
    }

    public function test_settlement_events_interleave_with_the_notes_across_pages_once_each_and_read_only(): void
    {
        foreach (range(1, 6) as $day) {
            $this->event(sprintf('2026-09-%02d 09:00:00', $day * 2));
        }
        $close = $this->settlementEvent('2026-09-11 10:00:00', 'close');
        $reopen = $this->settlementEvent('2026-09-03 10:00:00', 'reopen');
        $other = $this->account->clients()->create(['name' => 'Drugi Przykładowy']);
        $this->settlementEvent('2026-09-09 10:00:00', 'close', $other);

        $first = $this->journal()['notes'];
        $rest = $this->partial('journal_notes', ['wpisy' => $first['next']]);
        $rows = [...$first['rows'], ...$rest['rows']];
        $keys = array_column($rows, 'key');

        $this->assertCount(8, $keys);
        $this->assertCount(8, array_unique($keys));
        $this->assertContains('settlement-'.$close->id, array_column($first['rows'], 'key'));
        $this->assertContains('settlement-'.$reopen->id, array_column($rest['rows'], 'key'));
        $this->assertSame(['2026-09-12', '2026-09-11', '2026-09-10'], array_slice(array_column($first['rows'], 'date'), 0, 3));
        $settled = collect($rows)->firstWhere('key', 'settlement-'.$close->id);
        $this->assertSame('settlement', $settled['kind']);
        $this->assertSame(['action' => 'close', 'period' => '2026-08', 'used' => 32.0, 'contracted' => 30.0, 'closing' => -11.5, 'source' => 'report'], $settled['settlement']);
        $this->assertSame('Filip Chrapek', $settled['author']);
        $this->assertArrayNotHasKey('id', $settled, 'a settlement row is not a journal entry anyone can edit');
    }

    public function test_the_stage_line_dates_the_last_move_into_the_stage_and_the_leading_retainer(): void
    {
        $this->client->update(['lifecycle_stage' => 'active']);
        foreach ([['prospect', 'active', '2026-03-01'], ['active', 'paused', '2026-04-01'], ['paused', 'active', '2026-06-12 22:30:00'], ['active', 'active', '2026-07-01']] as [$from, $to, $at]) {
            ClientLifecycleEvent::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'from_stage' => $from, 'to_stage' => $to, 'created_at' => Carbon::parse($at, 'UTC')]);
        }
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 30, 'effective_from' => '2026-06-02']);
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'monthly_hours' => 2, 'effective_from' => '2026-08-01']);

        $this->assertSame(['stage' => 'active', 'since' => '2026-06-13', 'retainer_from' => '2026-06-02'], $this->journal()['stage']);
    }

    public function test_projects_carry_this_local_months_hours_busiest_first(): void
    {
        $busy = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Landing', 'trello_url' => 'https://trello.com/b/x']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Old', 'archived_at' => now()]);
        $this->entry('2026-09-30 22:30:00', 90, project: $busy);
        $this->entry('2026-10-31 23:30:00', 60, project: $busy);
        $this->entry('2026-09-30 21:30:00', 60, project: $busy);
        $this->entry('2026-10-02 09:00:00', 45);

        $journal = $this->journal();

        $this->assertSame('2026-10', $journal['period']);
        $this->assertSame([
            ['id' => $busy->id, 'name' => 'Landing', 'minutes' => 90, 'trello_url' => 'https://trello.com/b/x'],
            ['id' => $this->project->id, 'name' => 'Site', 'minutes' => 45, 'trello_url' => null],
            ['id' => $this->client->ensureGeneralProject()->id, 'name' => 'General', 'minutes' => 0, 'trello_url' => null],
        ], $journal['projects']);
    }

    public function test_another_accounts_records_never_reach_the_journal(): void
    {
        $other = Account::create(['name' => 'Other']);
        $stranger = $other->clients()->create(['name' => 'Pracownia Testowa', 'currency' => 'PLN']);
        $strangerProject = Project::create(['account_id' => $other->id, 'client_id' => $stranger->id, 'name' => 'Site']);
        TimeEntry::create([
            'account_id' => $other->id, 'project_id' => $strangerProject->id, 'client_id' => $stranger->id, 'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-10-01 09:00:00', 'end_time' => '2026-10-01 10:00:00', 'duration_minutes' => 60,
        ]);
        TimeEntry::create([
            'account_id' => $other->id, 'project_id' => $this->project->id, 'client_id' => $this->client->id, 'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => '2026-10-01 09:00:00', 'end_time' => '2026-10-01 10:00:00', 'duration_minutes' => 60,
        ]);
        ClientLifecycleEvent::create(['account_id' => $other->id, 'client_id' => $this->client->id, 'from_stage' => 'active', 'to_stage' => 'active', 'note' => 'Not yours', 'created_at' => now()]);

        $journal = $this->journal();

        $this->assertSame(['rows' => 0, 'notes' => 0, 'field_changes' => 0, 'sms' => 0, 'person_minutes' => 0], $journal['summary']);
        $this->assertSame([], $journal['work']['rows']);
        $this->assertSame([], $journal['notes']['rows']);
        $this->assertSame(0, $journal['projects'][0]['minutes']);
        $this->actingAs($this->user)->get("/clients/{$stranger->id}/dziennik")->assertNotFound();
    }

    public function test_the_relacja_screen_is_client_state_so_its_url_sends_the_same_props_and_queries(): void
    {
        $this->entry('2026-10-01 08:00:00', 60);
        $this->event('2026-10-02 08:00:00');
        $url = "/clients/{$this->client->id}/dziennik";
        $this->actingAs($this->user)->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $plain = $this->actingAs($this->user)->get($url)->assertOk()->viewData('page')['props'];
        $plainQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $pushed = $this->actingAs($this->user)->get("{$url}?relacja=1")->assertOk()->viewData('page')['props'];
        $pushedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        unset($plain['errors'], $pushed['errors']);
        $this->assertSame($plain, $pushed);
        $this->assertSame($plainQueries, $pushedQueries);
    }

    public function test_a_malformed_cursor_falls_back_to_the_first_page(): void
    {
        foreach (range(1, 12) as $n) {
            $this->entry(sprintf('2026-09-%02d 09:00:00', $n), 5);
        }

        foreach (['-4', 'abc', '1e9', '99999999999999'] as $bad) {
            $this->seenAt('2026-09-28 08:00:00');
            $this->assertSame('2026-09-12', $this->journal('?praca='.urlencode($bad).'&wpisy=x')['work']['rows'][0]['date']);
        }
        $this->seenAt('2026-09-28 08:00:00');
        $this->assertSame('2026-09-12', $this->journal('?praca[]=1')['work']['rows'][0]['date']);
    }

    private function settlementEvent(string $atUtc, string $action, ?Client $client = null): \App\Models\ClientMonthSettlementEvent
    {
        return \App\Models\ClientMonthSettlementEvent::create([
            'client_id' => ($client ?? $this->client)->id,
            'period' => '2026-08',
            'action' => $action,
            'snapshot' => ['used' => 32, 'contracted' => 30, 'closing' => -11.5, 'source' => 'report'],
            'actor_user_id' => $this->user->id,
            'created_at' => Carbon::parse($atUtc, 'UTC'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function journal(string $query = ''): array
    {
        $response = $this->actingAs($this->user)->get("/clients/{$this->client->id}/dziennik{$query}");
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        return [...$props['journal'], 'work' => $props['journal_work'], 'notes' => $props['journal_notes']];
    }

    /**
     * One list as "Starsze" asks for it: a partial reload of that prop alone.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function partial(string $prop, array $query): array
    {
        return $this->inertiaPartial($prop, $query)->assertOk()->json("props.{$prop}");
    }

    /** @param  array<string, string>  $query */
    private function inertiaPartial(string $prop, array $query): TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeaders([
                Header::INERTIA => 'true',
                Header::VERSION => (string) app(HandleInertiaRequests::class)->version(request()),
                Header::PARTIAL_COMPONENT => 'clients/show',
                Header::PARTIAL_ONLY => $prop,
            ])
            ->get("/clients/{$this->client->id}/dziennik?".http_build_query($query));
    }

    /** The user's previous visit, as the view row a past visit would have left. */
    private function seenAt(string $utc): void
    {
        ClientView::query()->updateOrCreate(
            ['user_id' => $this->user->id, 'client_id' => $this->client->id],
            ['viewed_at' => Carbon::parse($utc, 'UTC')],
        );
        Cache::flush();
    }

    private function entry(string $startUtc, int $minutes, ?string $session = null, ?Task $task = null, ?Project $project = null, ?string $description = null, int $minuteOffset = 0): TimeEntry
    {
        $start = Carbon::parse($startUtc, 'UTC')->addMinutes($minuteOffset);
        $entry = TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => ($project ?? $this->project)->id,
            'client_id' => $this->client->id,
            'task_id' => $task?->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'description' => $description,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
        ]);

        if ($session !== null) {
            $entry->forceFill(['actor_via' => 'cli', 'actor_session_id' => $session])->save();
        }

        return $entry;
    }

    private function event(string $atUtc, ?string $session = null): ClientLifecycleEvent
    {
        $event = ClientLifecycleEvent::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'user_id' => $this->user->id,
            'from_stage' => 'active',
            'to_stage' => 'active',
            'note' => 'Call about the shop',
            'created_at' => Carbon::parse($atUtc, 'UTC'),
        ]);

        if ($session !== null) {
            $event->forceFill(['actor_via' => 'cli', 'actor_session_id' => $session])->save();
        }

        return $event;
    }

    private function audit(string $session, string $table, int $id, string $action = 'created'): void
    {
        AgentAuditEvent::create([
            'account_id' => $this->account->id, 'actor' => 'user:Filip', 'via' => 'cli', 'session_id' => $session,
            'verb' => 'time:log', 'action' => $action, 'target_type' => $table, 'target_id' => $id,
        ]);
    }
}

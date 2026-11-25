<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\DayAddRequest;
use App\Models\DayClose;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\TimesOutThePlanLock;
use Tests\TestCase;

final class DayAddTaskTest extends TestCase
{
    use RefreshDatabase;
    use TimesOutThePlanLock;

    private Account $account;

    private User $user;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'Europe/Warsaw']);
        // Wednesday 16 September 2026, 10:00 in Warsaw.
        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));

        $this->account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'first_name' => 'F', 'last_name' => 'C', 'email' => 'u@example.com', 'owner' => true]);
        $this->client = Client::create(['account_id' => $this->account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{string}> */
    public static function displayZones(): array
    {
        return [['America/New_York'], ['Europe/Warsaw']];
    }

    public function test_a_new_task_lands_in_its_project_and_on_today_in_one_request(): void
    {
        $response = $this->actingAs($this->user)->postJson('/day/add', ['name' => '  New footer  ', 'project_id' => $this->project->id])
            ->assertCreated()
            ->assertJsonPath('task.name', 'New footer')
            ->assertJsonPath('task.client', 'ACME')
            ->assertJsonPath('task.project', 'Site')
            ->assertJsonPath('replayed', false);

        $task = Task::findOrFail($response->json('task.task_id'));
        $this->assertSame($this->project->id, $task->project_id);
        $this->assertSame('To-Do', $task->list_name);
        $this->assertSame([$task->id], DayPick::live()->whereDate('date', '2026-09-16')->pluck('task_id')->all());
        $this->assertSame($response->json('pick_id'), DayPick::firstOrFail()->id);
    }

    public function test_a_new_task_goes_on_the_date_it_was_added_for(): void
    {
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Thursday thing', 'project_id' => $this->project->id, 'date' => '2026-09-24'])->assertCreated();

        $this->assertSame(['2026-09-24'], DayPick::pluck('date')->map(fn ($date) => mb_substr((string) $date, 0, 10))->all());
    }

    public function test_a_repeated_submit_returns_the_first_task_instead_of_a_second(): void
    {
        $id = (string) Str::uuid();
        $first = $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Once', 'project_id' => $this->project->id, 'request_id' => $id])->assertCreated();
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Once', 'project_id' => $this->project->id, 'request_id' => $id])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('task.task_id', $first->json('task.task_id'));

        $this->assertSame(1, Task::count());
        $this->assertSame(1, DayPick::count());
    }

    public function test_a_request_id_sent_again_with_another_text_or_project_is_refused_and_makes_nothing(): void
    {
        $id = (string) Str::uuid();
        $other = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Other']);
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Once', 'project_id' => $this->project->id, 'request_id' => $id])->assertCreated();

        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Once more', 'project_id' => $this->project->id, 'request_id' => $id])->assertConflict();
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Once', 'project_id' => $other->id, 'request_id' => $id])->assertConflict();
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Once', 'project_id' => $this->project->id, 'date' => '2026-09-17', 'request_id' => $id])->assertConflict();

        $this->assertSame(['Once'], Task::pluck('name')->all());
        $this->assertSame(1, DayPick::count());
    }

    public function test_a_request_id_whose_task_was_deleted_is_refused_rather_than_making_the_task_again(): void
    {
        $id = (string) Str::uuid();
        $first = $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Gone', 'project_id' => $this->project->id, 'request_id' => $id])->assertCreated();
        Task::findOrFail($first->json('task.task_id'))->delete();

        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Gone', 'project_id' => $this->project->id, 'request_id' => $id])->assertConflict();

        $this->assertSame(0, Task::count());
    }

    public function test_a_request_id_belongs_to_its_account_and_the_database_holds_it_unique_there(): void
    {
        $id = (string) Str::uuid();
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Mine', 'project_id' => $this->project->id, 'request_id' => $id])->assertCreated();
        $otherAccount = Account::create(['name' => 'Other']);
        $otherUser = User::factory()->create(['account_id' => $otherAccount->id, 'first_name' => 'O', 'last_name' => 'U', 'email' => 'o@example.com', 'owner' => true]);
        $theirs = Project::create(['account_id' => $otherAccount->id, 'name' => 'Theirs']);

        $this->actingAs($otherUser)->postJson('/day/add', ['name' => 'Theirs too', 'project_id' => $theirs->id, 'request_id' => $id])->assertCreated();
        $this->assertSame(2, DayAddRequest::where('request_id', $id)->count());

        // Two racing requests both pass the lookup; the unique index lets one of them write.
        $this->expectException(UniqueConstraintViolationException::class);
        DayAddRequest::create(['account_id' => $this->account->id, 'request_id' => $id, 'fingerprint' => 'x', 'task_id' => 1]);
    }

    public function test_a_database_that_kept_the_request_id_on_tasks_loses_that_column_and_gets_the_table(): void
    {
        Schema::drop('day_add_requests');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('create_request_id', 64)->nullable();
            $table->unique(['project_id', 'create_request_id']);
        });

        (require database_path('migrations/2026_11_21_230000_create_day_add_requests_table.php'))->up();

        $this->assertFalse(Schema::hasColumn('tasks', 'create_request_id'));
        $this->assertTrue(Schema::hasTable('day_add_requests'));

        // A column left without its index, or neither, migrates as cleanly.
        Schema::drop('day_add_requests');
        Schema::table('tasks', fn (Blueprint $table) => $table->string('create_request_id', 64)->nullable());
        (require database_path('migrations/2026_11_21_230000_create_day_add_requests_table.php'))->up();
        Schema::drop('day_add_requests');
        (require database_path('migrations/2026_11_21_230000_create_day_add_requests_table.php'))->up();
        $this->assertFalse(Schema::hasColumn('tasks', 'create_request_id'));
    }

    public function test_requests_older_than_a_week_are_pruned_every_night(): void
    {
        $old = DayAddRequest::create(['account_id' => $this->account->id, 'request_id' => (string) Str::uuid(), 'fingerprint' => 'a', 'task_id' => 1]);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $fresh = DayAddRequest::create(['account_id' => $this->account->id, 'request_id' => (string) Str::uuid(), 'fingerprint' => 'b', 'task_id' => 2]);

        $this->artisan('model:prune', ['--model' => [DayAddRequest::class]])->assertSuccessful();

        $this->assertSame([$fresh->id], DayAddRequest::pluck('id')->all());
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'DayAddRequest')));
    }

    public function test_a_pick_that_fails_after_the_task_is_written_takes_the_task_back_with_it(): void
    {
        DayPick::creating(fn () => throw new RuntimeException('the pick fails'));

        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Half done', 'project_id' => $this->project->id])->assertServerError();

        $this->assertSame(0, Task::count());
        $this->assertSame(0, DayAddRequest::count());
    }

    public function test_the_inbox_project_takes_no_task_from_the_add_field(): void
    {
        $inbox = Project::accountInbox($this->account->id);

        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Routed', 'project_id' => $inbox->id])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertSame(0, Task::count());
    }

    public function test_a_search_needs_two_characters(): void
    {
        $this->actingAs($this->user)->getJson('/day/add/tasks?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->actingAs($this->user)->getJson('/day/add/tasks?q=ab')->assertOk();
    }

    public function test_a_project_of_another_account_or_an_archived_one_is_refused_and_nothing_is_written(): void
    {
        $other = Account::create(['name' => 'Other']);
        $foreign = Project::create(['account_id' => $other->id, 'name' => 'Theirs']);
        $archived = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Old', 'archived_at' => now()]);

        foreach ([$foreign, $archived] as $project) {
            $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Nope', 'project_id' => $project->id])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        }

        $this->assertSame(0, Task::count());
        $this->assertSame(0, DayPick::count());
    }

    public function test_a_project_whose_client_was_deleted_takes_no_new_task(): void
    {
        $this->client->delete();

        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Nope', 'project_id' => $this->project->id])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertSame(0, Task::count());
    }

    public function test_a_refused_pick_leaves_no_task_behind(): void
    {
        DayClose::create(['account_id' => $this->account->id, 'date' => '2026-09-16', 'closed_at' => now()]);
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'On a closed day', 'project_id' => $this->project->id])->assertUnprocessable()->assertJsonValidationErrors('task_id');

        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'In the past', 'project_id' => $this->project->id, 'date' => '2026-09-15'])->assertUnprocessable()->assertJsonValidationErrors('task_id');

        $this->assertSame(0, Task::count());
        $this->assertSame(0, DayPick::count());
    }

    public function test_a_busy_plan_writes_neither_the_task_nor_the_pick(): void
    {
        $this->planLockTimesOut();

        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Waiting', 'project_id' => $this->project->id])->assertUnprocessable()->assertJsonValidationErrors('task_id');

        $this->assertSame(0, Task::count());
    }

    public function test_a_full_day_still_takes_a_sixth_task(): void
    {
        foreach (range(1, 5) as $n) {
            $this->actingAs($this->user)->postJson('/day/add', ['name' => "Task {$n}", 'project_id' => $this->project->id])->assertCreated();
        }
        $this->actingAs($this->user)->postJson('/day/add', ['name' => 'Sixth', 'project_id' => $this->project->id])->assertCreated();

        $this->assertSame(6, DayPick::live()->whereDate('date', '2026-09-16')->count());
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->actingAs($this->user)->postJson('/day/add', ['name' => '   ', 'project_id' => $this->project->id])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(0, Task::count());
    }

    public function test_matches_come_in_the_plans_order_without_the_dates_picks_or_other_accounts(): void
    {
        $late = $this->task('Pricing page', due: '2026-09-30');
        $soon = $this->task('Pricing PDF', due: '2026-09-18');
        $leftover = $this->task('Pricing table');
        $onTomorrow = $this->task('Pricing mail');
        $this->task('Unrelated');
        Task::create(['project_id' => $this->project->id, 'name' => 'Pricing done', 'is_completed' => true]);
        $other = Account::create(['name' => 'Other']);
        Task::create(['project_id' => Project::create(['account_id' => $other->id, 'name' => 'Theirs'])->id, 'name' => 'Pricing theirs']);
        $this->pickOn($leftover, '2026-09-16');
        $this->pickOn($onTomorrow, '2026-09-17');

        $this->actingAs($this->user)->getJson('/day/add/tasks?q=pricing&date=2026-09-17')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('tasks.*.task_id', [$leftover->id, $soon->id, $late->id])
            ->assertJsonPath('tasks.0.client', 'ACME')
            ->assertJsonPath('tasks.0.project', 'Site');
    }

    public function test_cards_due_the_same_local_day_keep_their_lane_order_whatever_the_hour(): void
    {
        // Both fall on Friday 18 September in Warsaw: the day ties, so Doing leads Backlog though Backlog is due earlier in it.
        Task::create(['project_id' => $this->project->id, 'name' => 'Card on Backlog', 'list_name' => 'Backlog', 'trello_card_id' => 'c1', 'due_date' => Carbon::parse('2026-09-18 04:00:00', 'UTC')]);
        Task::create(['project_id' => $this->project->id, 'name' => 'Card in Doing', 'list_name' => 'Doing', 'trello_card_id' => 'c2', 'due_date' => Carbon::parse('2026-09-18 12:00:00', 'UTC')]);
        Task::create(['project_id' => $this->project->id, 'name' => 'Card later', 'list_name' => 'Doing', 'trello_card_id' => 'c3', 'due_date' => Carbon::parse('2026-09-19 08:00:00', 'UTC')]);

        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('suggestions.tasks', fn ($tasks) => collect($tasks)->pluck('name')->all() === ['Card in Doing', 'Card on Backlog', 'Card later']));
        $this->actingAs($this->user)->getJson('/day/add/tasks?q=card&date=2026-09-17')
            ->assertJsonPath('tasks.*.name', ['Card in Doing', 'Card on Backlog', 'Card later']);
    }

    public function test_the_plan_order_reads_one_day_past_its_cut_and_matches_the_full_sort_across_a_clock_change(): void
    {
        $lanes = ['Doing', 'To-Do', 'Testing', 'Review', 'Backlog'];
        $made = collect();
        $card = function (string $name, ?string $dueUtc, int $lane, bool $trello = true) use (&$made, $lanes): void {
            $made->push(Task::create([
                'project_id' => $this->project->id, 'name' => $name, 'list_name' => $lanes[$lane],
                'trello_card_id' => $trello ? 'c-'.$name : null, 'due_date' => $dueUtc === null ? null : Carbon::parse($dueUtc, 'UTC'),
            ]));
        };
        // Sunday 25 October 2026 is the day the clocks go back: 00:30 local is 22:30 UTC on the 24th, 23:30 local is 22:30 UTC on the 25th.
        $card('Card 001', '2026-10-23 09:00:00', 4);
        $card('Card 002', '2026-10-23 15:00:00', 0);
        $card('Card 003', '2026-10-24 22:30:00', 4);
        $card('Card 004', '2026-10-25 06:00:00', 2);
        $card('Card 005', '2026-10-25 12:00:00', 4);
        $card('Card 006', '2026-10-25 22:30:00', 0);
        $card('Card 007', '2026-10-25 00:00:00', 1, trello: false);
        $card('Card 008', '2026-10-25 21:00:00', 0);
        foreach (range(100, 399) as $n) {
            $card(sprintf('Card %03d', $n), sprintf('2026-11-%02d %02d:00:00', 1 + $n % 28, $n % 24), $n % 5);
        }
        foreach (range(400, 409) as $n) {
            $card(sprintf('Card %03d', $n), null, $n % 5);
        }

        $rank = fn (Task $task): int => ['Doing' => 1, 'To-Do' => 2, 'Testing' => 3, 'Backlog' => 5][$task->list_name] ?? 4;
        $full = fn (int $limit): array => $made->map(fn (Task $task) => $task->fresh())
            ->sortBy(fn (Task $task): array => [$task->dueDay() === null, $task->dueDay(), $rank($task), $task->name])
            ->take($limit)->pluck('name')->values()->all();

        $loaded = 0;
        Event::listen('eloquent.retrieved: '.Task::class, function () use (&$loaded): void {
            $loaded++;
        });
        $names = $this->actingAs($this->user)->getJson('/day/add/tasks?q=card&date=2026-09-17')->assertOk()->json('tasks.*.name');
        $read = $loaded;

        // Local 25 October holds 003 to 008: 003 is 00:30 there, 006 is 23:30. Its Doing cards lead it whatever the hour.
        $this->assertSame($full(6), $names);
        $this->assertSame(['Card 002', 'Card 001', 'Card 006', 'Card 008', 'Card 007', 'Card 004'], $names);
        $this->assertLessThan(30, $read, 'the six asked for, the rest of their last day and the six shown, never the 318 that match');
    }

    public function test_ten_thousand_cards_due_one_local_day_read_no_more_than_the_places_asked_for(): void
    {
        $lanes = ['Doing', 'To-Do', 'Testing', 'Review', 'Backlog'];
        foreach (array_chunk(range(1, 10000), 500) as $chunk) {
            DB::table('tasks')->insert(array_map(fn (int $n): array => [
                'project_id' => $this->project->id, 'name' => sprintf('Card %05d', $n), 'list_name' => $lanes[$n % 5],
                'trello_card_id' => 'c'.$n, 'is_completed' => false, 'is_reviewed' => true,
                // All on Sunday 25 October in Warsaw, the day the clocks go back, eight seconds apart from its first minute.
                'due_date' => Carbon::parse('2026-10-24 22:00:00', 'UTC')->addSeconds($n * 8)->toDateTimeString(),
                'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }
        $leftover = Task::create(['project_id' => $this->project->id, 'name' => 'Card left over', 'list_name' => 'Backlog']);
        $this->pickOn($leftover, '2026-09-16');

        $loaded = 0;
        Event::listen('eloquent.retrieved: '.Task::class, function () use (&$loaded): void {
            $loaded++;
        });
        $names = $this->actingAs($this->user)->getJson('/day/add/tasks?q=card&date=2026-09-17')->assertOk()->json('tasks.*.name');
        $read = $loaded;

        // The leftover leads; then the day's Doing cards by name, as the full sort orders them.
        $this->assertSame(['Card left over', 'Card 00005', 'Card 00010', 'Card 00015', 'Card 00020', 'Card 00025'], $names);
        $this->assertLessThanOrEqual(1 + 5 + 5 + 6, $read, 'the leftover, the places reached, the day cut to the places left, and the six shown');
    }

    #[DataProvider('displayZones')]
    public function test_crm_dates_and_card_instants_of_one_day_mix_in_lane_order_east_or_west_of_utc(string $zone): void
    {
        config(['app.display_timezone' => $zone]);
        foreach (range(1, 6) as $n) {
            Task::create(['project_id' => $this->project->id, 'name' => sprintf('Task %02d', $n), 'list_name' => 'Backlog', 'due_date' => '2026-11-01']);
        }
        // 15:00 UTC on 1 November is 1 November in both zones; a Doing card leads a Backlog day.
        Task::create(['project_id' => $this->project->id, 'name' => 'Task card', 'list_name' => 'Doing', 'trello_card_id' => 'c1', 'due_date' => Carbon::parse('2026-11-01 15:00:00', 'UTC')]);
        // 02:00 UTC on 2 November is still 1 November in New York, 2 November in Warsaw.
        Task::create(['project_id' => $this->project->id, 'name' => 'Task late card', 'list_name' => 'Doing', 'trello_card_id' => 'c2', 'due_date' => Carbon::parse('2026-11-02 02:00:00', 'UTC')]);

        $names = $this->actingAs($this->user)->getJson('/day/add/tasks?q=task&date=2026-09-17')->assertOk()->json('tasks.*.name');

        $this->assertSame($this->fullSort('task', 6), $names);
        $this->assertSame('Task card', $names[0]);
        $this->assertSame($zone === 'America/New_York' ? 'Task late card' : 'Task 01', $names[1]);
    }

    #[DataProvider('displayZones')]
    public function test_ten_thousand_crm_tasks_due_one_date_read_no_more_than_the_places_asked_for(string $zone): void
    {
        config(['app.display_timezone' => $zone]);
        $lanes = ['Doing', 'To-Do', 'Testing', 'Review', 'Backlog'];
        foreach (array_chunk(range(1, 10000), 500) as $chunk) {
            DB::table('tasks')->insert(array_map(fn (int $n): array => [
                'project_id' => $this->project->id, 'name' => sprintf('Task %05d', $n), 'list_name' => $lanes[$n % 5],
                'is_completed' => false, 'is_reviewed' => true, 'due_date' => '2026-11-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }
        $leftover = Task::create(['project_id' => $this->project->id, 'name' => 'Task left over', 'list_name' => 'Backlog']);
        $this->pickOn($leftover, '2026-09-16');

        $loaded = 0;
        Event::listen('eloquent.retrieved: '.Task::class, function () use (&$loaded): void {
            $loaded++;
        });
        $names = $this->actingAs($this->user)->getJson('/day/add/tasks?q=task&date=2026-09-17')->assertOk()->json('tasks.*.name');
        $read = $loaded;

        $this->assertSame(['Task left over', 'Task 00005', 'Task 00010', 'Task 00015', 'Task 00020', 'Task 00025'], $names);
        $this->assertLessThanOrEqual(5 + 6, $read, 'the five candidates read once and the six shown');
    }

    public function test_with_nothing_typed_the_tasks_worked_on_last_come_back(): void
    {
        $old = $this->task('Old one');
        $worked = $this->task('Worked on');
        $picked = $this->task('Already today');
        TimeEntry::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'project_id' => $this->project->id, 'task_id' => $worked->id, 'start_time' => now()->subHour(), 'end_time' => now()->subMinutes(30), 'duration_minutes' => 30]);
        $old->forceFill(['updated_at' => now()->subWeek()])->saveQuietly();
        $this->pickOn($picked, '2026-09-16');

        $this->actingAs($this->user)->getJson('/day/add/tasks')
            ->assertOk()
            ->assertJsonPath('recent', true)
            ->assertJsonPath('tasks.*.task_id', [$worked->id, $old->id]);
    }

    public function test_the_project_choices_are_the_accounts_live_projects_with_the_recent_ones_named(): void
    {
        $beta = Client::create(['account_id' => $this->account->id, 'name' => 'Beta', 'type' => 'business']);
        $shop = Project::create(['account_id' => $this->account->id, 'client_id' => $beta->id, 'name' => 'Shop']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $beta->id, 'name' => 'Gone', 'archived_at' => now()]);
        $gone = Client::create(['account_id' => $this->account->id, 'name' => 'Deleted', 'type' => 'business']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $gone->id, 'name' => 'Orphan']);
        $gone->delete();
        Project::create(['account_id' => Account::create(['name' => 'Other'])->id, 'name' => 'Theirs']);
        TimeEntry::create(['account_id' => $this->account->id, 'client_id' => $beta->id, 'project_id' => $shop->id, 'start_time' => now()->subHour(), 'end_time' => now()->subMinutes(30), 'duration_minutes' => 30]);

        $this->actingAs($this->user)->getJson('/day/add/projects')
            ->assertOk()
            ->assertJsonPath('recent', [$shop->id])
            // every client comes with its General project
            ->assertJsonPath('projects.*.name', ['General', 'Site', 'General', 'Shop'])
            ->assertJsonPath('projects.*.client', ['ACME', 'ACME', 'Beta', 'Beta']);
    }

    public function test_the_running_timers_name_their_project_for_the_add_fields_preset(): void
    {
        TimeEntry::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'project_id' => $this->project->id, 'start_time' => now()->subMinutes(5)]);

        $this->actingAs($this->user)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('timers.0.project_id', $this->project->id));
        $this->actingAs($this->user)->get('/tomorrow')
            ->assertInertia(fn (Assert $page) => $page->where('timers.0.project_id', $this->project->id));
    }

    /** @return list<string> the first `$limit` names of a full sort by the plan's rules, as the old code ordered them */
    private function fullSort(string $text, int $limit): array
    {
        $rank = fn (Task $task): int => ['Doing' => 1, 'To-Do' => 2, 'Testing' => 3, 'Backlog' => 5][$task->list_name] ?? 4;

        return Task::query()->where('name', 'like', "%{$text}%")->get()
            ->sortBy(fn (Task $task): array => [$task->dueDay() === null, $task->dueDay(), $rank($task), $task->name])
            ->take($limit)->pluck('name')->values()->all();
    }

    private function task(string $name, ?string $due = null): Task
    {
        return Task::create(['project_id' => $this->project->id, 'name' => $name, 'due_date' => $due]);
    }

    private function pickOn(Task $task, string $date): void
    {
        $this->actingAs($this->user)->post('/day/picks', ['task_id' => $task->id, 'date' => $date])->assertSessionHasNoErrors();
    }
}

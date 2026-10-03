<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\ClientBrief;
use App\Services\Agent\MonthCloseTicker;
use App\Services\Agent\TodayDigest;
use App\Services\Reports\ReportDataAggregator;
use App\Support\LocalCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Days and months are the owner's local calendar; storage is UTC. The cases
 * sit 30 minutes either side of a local month boundary, in Warsaw winter
 * time (UTC+1), Warsaw summer time (UTC+2) and plain UTC.
 */
final class DisplayTimezoneBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acme']);
        $this->client = $this->account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Site',
        ]);
    }

    /**
     * A UTC instant shortly after a local midnight on the 1st, the month it is
     * in locally, and the month before it.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function boundaries(): array
    {
        return [
            'Warsaw winter, 00:30 on 1 November' => ['Europe/Warsaw', '2026-10-31 23:30:00', '2026-11', '2026-10'],
            'Warsaw summer, 00:30 on 1 July' => ['Europe/Warsaw', '2026-06-30 22:30:00', '2026-07', '2026-06'],
            'UTC, 00:30 on 1 November' => ['UTC', '2026-11-01 00:30:00', '2026-11', '2026-10'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_default_period_is_the_local_month_just_ended(string $tz, string $utcNow, string $current, string $previous): void
    {
        config(['app.display_timezone' => $tz]);
        $this->travelTo(Carbon::parse($utcNow, 'UTC'));

        $this->assertSame($current, LocalCalendar::currentMonth());
        $this->assertSame($previous, LocalCalendar::previousMonth());
        $this->assertSame($previous, app(MonthCloseTicker::class)->defaultPeriod());

        $this->artisan('reports:generate', ['client' => $this->client->id, '--composer' => 'structured_list'])->assertSuccessful();
        $this->assertSame($previous.'-01', $this->client->reports()->sole()->period_start->toDateString());
    }

    #[DataProvider('boundaries')]
    public function test_work_just_after_local_midnight_lands_in_the_local_month(string $tz, string $utcNow, string $current, string $previous): void
    {
        config(['app.display_timezone' => $tz]);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Hero', 'is_reportable' => true]);
        $this->entry($task, Carbon::parse($utcNow, 'UTC'), 60);

        $aggregator = app(ReportDataAggregator::class);
        $hours = fn (string $month): float => $aggregator->aggregate(
            $this->client,
            Carbon::parse($month.'-01'),
            Carbon::parse($month.'-01')->endOfMonth(),
            'month',
        )->actualHours;

        $this->assertSame(1.0, $hours($current));
        $this->assertSame(0.0, $hours($previous));
    }

    #[DataProvider('boundaries')]
    public function test_month_to_date_hours_start_at_local_midnight(string $tz, string $utcNow, string $current, string $previous): void
    {
        config(['app.display_timezone' => $tz]);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Hero']);
        $this->entry($task, Carbon::parse($utcNow, 'UTC'), 60);
        $this->entry($task, Carbon::parse($utcNow, 'UTC')->subHour(), 30);
        $this->travelTo(Carbon::parse($utcNow, 'UTC')->addHours(2));

        $this->assertSame($current, LocalCalendar::currentMonth());
        $this->assertSame(1.0, app(ClientBrief::class)->for($this->client)['hours']['month']);
    }

    #[DataProvider('boundaries')]
    public function test_a_task_is_overdue_only_once_its_due_day_has_ended_locally(string $tz, string $utcNow, string $current, string $previous): void
    {
        config(['app.display_timezone' => $tz]);
        $local = Carbon::parse($utcNow, 'UTC')->setTimezone($tz);
        // Due on the local day before "now": overdue. Due on the local today: not yet.
        $yesterday = Task::create(['project_id' => $this->project->id, 'name' => 'Yesterday', 'due_date' => $local->copy()->subDay()->toDateString()]);
        $today = Task::create(['project_id' => $this->project->id, 'name' => 'Today', 'due_date' => $local->toDateString()]);
        $this->travelTo(Carbon::parse($utcNow, 'UTC'));

        $this->assertTrue($yesterday->fresh()->isOverdue());
        $this->assertFalse($today->fresh()->isOverdue());

        $digest = collect(app(TodayDigest::class)->build($this->account->id)['attention_tasks']);
        $this->assertSame(['Yesterday'], $digest->pluck('name')->all());
        $this->assertTrue($digest->first()['overdue']);
    }

    public function test_a_date_only_due_date_is_a_local_calendar_date_west_of_utc(): void
    {
        config(['app.display_timezone' => 'America/New_York']);
        // 20:00 on 5 November in New York is already 6 November in UTC.
        $this->travelTo(Carbon::parse('2026-11-06 01:00:00', 'UTC'));
        $today = Task::create(['project_id' => $this->project->id, 'name' => 'Today', 'due_date' => '2026-11-05']);
        $yesterday = Task::create(['project_id' => $this->project->id, 'name' => 'Yesterday', 'due_date' => '2026-11-04']);

        $this->assertFalse($today->fresh()->isOverdue());
        $this->assertTrue($yesterday->fresh()->isOverdue());
        $this->assertSame(['Yesterday'], collect(app(TodayDigest::class)->build($this->account->id)['attention_tasks'])->pluck('name')->all());
    }

    public function test_a_card_due_is_an_instant_read_in_the_local_calendar(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        // Due 23:30 UTC on 5 November is 00:30 on 6 November in Warsaw.
        $card = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Card',
            'source' => 'trello',
            'trello_card_id' => 'c1',
            'due_date' => '2026-11-05 23:30:00',
        ]);

        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Warsaw')->utc());
        $this->assertSame('2026-11-06', $card->fresh()->dueDay());
        $this->assertFalse($card->fresh()->isOverdue());

        $this->travelTo(Carbon::parse('2026-11-07 00:10:00', 'Europe/Warsaw')->utc());
        $this->assertTrue($card->fresh()->isOverdue());
    }

    public function test_the_project_board_carries_the_server_overdue_flag(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Warsaw')->utc());
        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        Task::create(['project_id' => $this->project->id, 'name' => 'Late', 'due_date' => '2026-11-05']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Due today', 'due_date' => '2026-11-06']);

        $this->actingAs($user)
            ->get("/clients/{$this->client->id}/projects/{$this->project->id}")
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('tasks', fn ($tasks) => collect($tasks)->pluck('is_overdue', 'name')->all() === ['Late' => true, 'Due today' => false]));
    }

    #[DataProvider('boundaries')]
    public function test_a_recurring_successor_is_due_counted_from_the_local_day(string $tz, string $utcNow, string $current, string $previous): void
    {
        config(['app.display_timezone' => $tz]);
        $this->travelTo(Carbon::parse($utcNow, 'UTC'));
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Backups', 'source' => 'manual', 'recurrence_period_days' => 7]);

        $this->artisan('crm:task-done', ['task' => $task->id])->assertSuccessful();

        $expected = Carbon::parse($utcNow, 'UTC')->setTimezone($tz)->addDays(7)->toDateString();
        $successor = Task::query()->whereKeyNot($task->id)->sole();
        $this->assertSame($expected, $successor->due_date->toDateString());
    }

    public function test_month_ranges_run_from_local_midnight_to_the_next_local_midnight_exclusive(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);

        [$from, $to] = LocalCalendar::monthRange('2026-11');
        $this->assertSame('2026-10-31 23:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-30 23:00:00', $to->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $from->getTimezone()->getName());

        [$from, $to] = LocalCalendar::monthRange('2026-07');
        $this->assertSame('2026-06-30 22:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-31 22:00:00', $to->format('Y-m-d H:i:s'));
    }

    public function test_an_entry_in_the_last_second_of_a_month_falls_in_that_month_only(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Hero', 'is_reportable' => true]);
        foreach (['2026-11-30 22:59:59.500', '2026-11-30 23:00:00'] as $start) {
            \Illuminate\Support\Facades\DB::table('time_entries')->insert([
                'account_id' => $this->account->id,
                'project_id' => $this->project->id,
                'client_id' => $this->client->id,
                'task_id' => $task->id,
                'source' => TimeEntry::SOURCE_MANUAL,
                'start_time' => $start,
                'end_time' => '2026-12-01 00:00:00',
                'duration_minutes' => 30,
                'billable' => true,
            ]);
        }

        $aggregator = app(ReportDataAggregator::class);
        $hours = fn (string $month): float => $aggregator->aggregate(
            $this->client,
            Carbon::parse($month.'-01'),
            Carbon::parse($month.'-01')->endOfMonth(),
            'month',
        )->actualHours;

        $this->assertSame(0.5, $hours('2026-11'));
        $this->assertSame(0.5, $hours('2026-12'));
    }

    public function test_an_offset_bearing_manual_entry_is_stored_in_utc(): void
    {
        $user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Hero']);

        $this->actingAs($user)
            ->postJson("/tasks/{$task->id}/time-entries", [
                'start_time' => '2026-07-01T00:30:00+02:00',
                'end_time' => '2026-07-01T01:30:00+02:00',
            ])
            ->assertCreated();

        $entry = TimeEntry::query()->sole();
        $this->assertSame('2026-06-30 22:30:00', $entry->getRawOriginal('start_time'));
        $this->assertSame('2026-06-30 23:30:00', $entry->getRawOriginal('end_time'));

        $this->actingAs($user)
            ->putJson("/time-entries/{$entry->id}", [
                'start_time' => '2026-07-01T09:00:00+02:00',
                'end_time' => '2026-07-01T10:00:00+02:00',
            ])
            ->assertSuccessful();

        $entry->refresh();
        $this->assertSame('2026-07-01 07:00:00', $entry->getRawOriginal('start_time'));
        $this->assertSame('2026-07-01 08:00:00', $entry->getRawOriginal('end_time'));
    }

    private function entry(Task $task, Carbon $start, int $minutes): void
    {
        TimeEntry::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
            'billable' => true,
        ]);
    }
}

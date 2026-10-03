<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

/**
 * Coverage for `php artisan time:log` (LogTimeCommand) — the CLI mirror of
 * TimeEntriesController::store() used from the side terminal. See
 * docs/development/quick-db-ops.md.
 */
final class LogTimeCommandTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Acc']);
        User::factory()->create([
            'account_id' => $this->account->id,
            'first_name' => 'F',
            'last_name' => 'C',
            'email' => 'u@example.com',
            'owner' => true,
        ]);
        $this->client = Client::create([
            'account_id' => $this->account->id,
            'name' => 'ACME',
            'type' => 'business',
        ]);
        $this->project = Project::create([
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
            'name' => 'Site',
        ]);
        $this->task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Hero work',
        ]);
    }

    public function test_logs_time_against_a_task_deriving_account_project_client(): void
    {
        $this->artisan('time:log', [
            'minutes' => 90,
            '--task' => $this->task->id,
            '--desc' => 'Fixed retainer hours',
        ])->assertExitCode(Command::SUCCESS);

        $entry = TimeEntry::sole();
        $this->assertSame(TimeEntry::SOURCE_MANUAL, $entry->source);
        $this->assertSame($this->task->id, $entry->task_id);
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame($this->client->id, $entry->client_id);
        $this->assertSame($this->account->id, $entry->account_id);
        $this->assertSame(90, $entry->duration_minutes);
        $this->assertSame('Fixed retainer hours', $entry->description);
        $this->assertTrue($entry->billable);
        // Closed entry: both ends set and consistent.
        $this->assertNotNull($entry->end_time);
        $this->assertSame(90, (int) $entry->start_time->diffInMinutes($entry->end_time));
    }

    public function test_description_defaults_to_task_name(): void
    {
        $this->artisan('time:log', ['minutes' => 15, '--task' => $this->task->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame('Hero work', TimeEntry::sole()->description);
    }

    public function test_logs_time_to_a_client_general_project_with_no_task(): void
    {
        $this->artisan('time:log', [
            'minutes' => 60,
            '--client' => $this->client->id,
        ])->assertExitCode(Command::SUCCESS);

        $entry = TimeEntry::sole();
        $this->assertNull($entry->task_id);
        $this->assertSame($this->client->id, $entry->client_id);
        $this->assertSame(60, $entry->duration_minutes);
        $this->assertSame($this->client->id, $entry->project->client_id);
    }

    public function test_backdated_end_and_non_billable(): void
    {
        // Display timezone is pinned to UTC in phpunit.xml, so wall-clock in == stored.
        $this->artisan('time:log', [
            'minutes' => 120,
            '--task' => $this->task->id,
            '--end' => '2026-07-13 17:00',
            '--not-billable' => true,
        ])->assertExitCode(Command::SUCCESS);

        $entry = TimeEntry::sole();
        $this->assertFalse($entry->billable);
        $this->assertSame(120, $entry->duration_minutes);
        $this->assertSame('17:00', $entry->end_time->format('H:i'));
        $this->assertSame('15:00', $entry->start_time->format('H:i'));
    }

    public function test_end_is_read_in_the_display_timezone_and_stored_as_utc(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);

        // 11:00 Warsaw (CEST, +02:00) is 09:00 UTC.
        $this->artisan('time:log', [
            'minutes' => 30,
            '--task' => $this->task->id,
            '--end' => '2026-07-21 11:00',
        ])->assertExitCode(Command::SUCCESS);

        $entry = TimeEntry::sole();
        $this->assertSame('2026-07-21 09:00', $entry->end_time->format('Y-m-d H:i'));
        $this->assertSame('2026-07-21 08:30', $entry->start_time->format('Y-m-d H:i'));
        $this->assertSame(30, $entry->duration_minutes);

        // The raw column must be UTC too — not the wall-clock string we were given.
        $this->assertSame('2026-07-21 09:00:00', $entry->getRawOriginal('end_time'));
    }

    public function test_end_honours_an_explicit_offset_over_the_display_timezone(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);

        $this->artisan('time:log', [
            'minutes' => 60,
            '--task' => $this->task->id,
            '--end' => '2026-07-21T17:00:00+00:00',
        ])->assertExitCode(Command::SUCCESS);

        // Explicit +00:00 wins; it is not re-interpreted as Warsaw time.
        $this->assertSame('17:00', TimeEntry::sole()->end_time->format('H:i'));
    }

    public function test_default_end_is_now_and_stores_utc(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);

        $this->artisan('time:log', [
            'minutes' => 15,
            '--task' => $this->task->id,
        ])->assertExitCode(Command::SUCCESS);

        $entry = TimeEntry::sole();
        // Within a minute of real UTC now — proves "now" isn't shifted by display tz.
        $this->assertLessThanOrEqual(60, abs($entry->end_time->diffInSeconds(now())));
    }

    public function test_requires_exactly_one_target(): void
    {
        $this->artisan('time:log', ['minutes' => 30])
            ->assertExitCode(Command::INVALID);
        $this->artisan('time:log', [
            'minutes' => 30,
            '--task' => $this->task->id,
            '--client' => $this->client->id,
        ])->assertExitCode(Command::INVALID);

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_rejects_non_positive_minutes(): void
    {
        $this->artisan('time:log', ['minutes' => 0, '--task' => $this->task->id])
            ->assertExitCode(Command::INVALID);

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_ambiguous_task_name_is_rejected_without_logging(): void
    {
        Task::create(['project_id' => $this->project->id, 'name' => 'Hero polish']);

        // "Hero" now matches both "Hero work" and "Hero polish".
        $this->artisan('time:log', ['minutes' => 30, '--task' => 'Hero'])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_unknown_task_is_rejected(): void
    {
        $this->artisan('time:log', ['minutes' => 30, '--task' => '999999'])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, TimeEntry::count());
    }
}

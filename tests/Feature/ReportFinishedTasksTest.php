<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Reports\ReportDataAggregator;
use App\Services\Tasks\TaskCompletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Finished in the period" is the task's own finish date, so editing a task
 * or syncing its card later never moves it into another report.
 */
final class ReportFinishedTasksTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acme']);
        $this->client = $account->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_task_finished_in_march_stays_in_march_after_an_april_edit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Checkout fix', 'source' => 'manual', 'is_reportable' => true]);
        app(TaskCompletion::class)->finish($task);

        Carbon::setTestNow(Carbon::parse('2026-04-10 09:00:00'));
        $task->update(['description' => 'Notes added later']);

        $this->assertSame(['Checkout fix'], $this->taskNames('2026-03-01', '2026-03-31'));
        $this->assertSame([], $this->taskNames('2026-04-01', '2026-04-30'));
        $this->assertSame('2026-03-20T12:00:00+00:00', $this->tasks('2026-03-01', '2026-03-31')[0]['completed_at']);
    }

    public function test_a_trello_card_finished_by_the_owner_counts_while_it_waits_in_testing(): void
    {
        Task::create([
            'project_id' => $this->project->id, 'name' => 'Banner', 'source' => 'trello', 'trello_card_id' => 'c1',
            'list_name' => 'Testing', 'is_completed' => false, 'is_reportable' => true, 'finished_at' => '2026-03-12 10:00:00',
        ]);

        $this->assertSame(['Banner'], $this->taskNames('2026-03-01', '2026-03-31'));
    }

    public function test_the_backfill_dates_completed_tasks_by_their_last_update(): void
    {
        $done = Task::create(['project_id' => $this->project->id, 'name' => 'Old done', 'is_completed' => true]);
        $open = Task::create(['project_id' => $this->project->id, 'name' => 'Old open', 'is_completed' => false]);
        DB::table('tasks')->whereIn('id', [$done->id, $open->id])->update(['finished_at' => null, 'updated_at' => '2026-02-14 09:30:00']);

        (require database_path('migrations/2026_10_01_090100_backfill_finished_at_on_completed_tasks.php'))->up();

        $this->assertSame('2026-02-14 09:30:00', $done->fresh()->finished_at->toDateTimeString());
        $this->assertNull($open->fresh()->finished_at);
    }

    public function test_a_task_worked_in_august_and_closed_in_september_stays_in_the_august_report(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id, 'name' => 'Cookie banner', 'source' => 'manual',
            'is_reportable' => true, 'is_completed' => true, 'finished_at' => '2026-09-03 07:45:00',
        ]);
        $this->logTime($task, '2026-08-20 08:00:00', 90);

        $this->assertSame(['Cookie banner'], $this->taskNames('2026-08-01', '2026-08-31'));
        $this->assertSame([], $this->taskNames('2026-09-01', '2026-09-30'));
    }

    public function test_a_task_closed_in_september_with_september_time_appears_in_september(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id, 'name' => 'Contact form', 'source' => 'manual',
            'is_reportable' => true, 'is_completed' => true, 'finished_at' => '2026-09-21 16:45:00',
        ]);
        $this->logTime($task, '2026-08-20 08:00:00', 60);
        $this->logTime($task, '2026-09-10 08:00:00', 30);

        $this->assertSame(['Contact form'], $this->taskNames('2026-09-01', '2026-09-30'));
    }

    public function test_an_entry_on_the_local_first_of_the_month_counts_as_that_months_work(): void
    {
        // 1 September 00:30 in Warsaw is 31 August 22:30 UTC.
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $task = Task::create([
            'project_id' => $this->project->id, 'name' => 'Early fix', 'source' => 'manual',
            'is_reportable' => true, 'is_completed' => true, 'finished_at' => '2026-09-03 07:45:00',
        ]);
        $this->logTime($task, '2026-08-31 22:30:00', 15);

        $this->assertSame(['Early fix'], $this->taskNames('2026-09-01', '2026-09-30'));
    }

    private function logTime(Task $task, string $startUtc, int $minutes): void
    {
        $start = Carbon::parse($startUtc, 'UTC');
        TimeEntry::create([
            'account_id' => $this->client->account_id, 'project_id' => $this->project->id, 'client_id' => $this->client->id,
            'task_id' => $task->id, 'source' => 'manual', 'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes), 'duration_minutes' => $minutes,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(string $from, string $to): array
    {
        return app(ReportDataAggregator::class)
            ->aggregate($this->client, Carbon::parse($from), Carbon::parse($to), 'month')
            ->tasks->values()->all();
    }

    /**
     * @return list<string>
     */
    private function taskNames(string $from, string $to): array
    {
        return array_column($this->tasks($from, $to), 'name');
    }
}

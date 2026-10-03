<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\TimerStartTool;
use App\Mcp\Tools\TimerStopTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A timer is started and stopped the same way from the web, the CLI and MCP:
 * same row shape on start, same minutes on stop (rounded up).
 */
final class TimerParityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 08:00:00', 'UTC'));
        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_start_path_opens_the_same_row(): void
    {
        $starts = [
            'web' => fn (Task $task) => $this->actingAs($this->user)->postJson("/tasks/{$task->id}/time-entries/start")->assertCreated(),
            'cli' => fn (Task $task) => $this->artisan('crm:timer-start', ['task' => (string) $task->id])->assertSuccessful(),
            'mcp' => fn (Task $task) => CrmServer::tool(TimerStartTool::class, ['task' => (string) $task->id])->assertOk(),
        ];

        $rows = collect($starts)->map(function (Closure $start, string $path) {
            $task = Task::create(['project_id' => $this->project->id, 'name' => "Header {$path}", 'source' => 'manual']);
            $start($task);
            $entry = TimeEntry::where('task_id', $task->id)->sole();

            return [
                'title' => $entry->title === $task->name,
                'description' => $entry->description === $task->name,
                'source' => $entry->source,
                'billable' => (bool) $entry->billable,
                'client' => $entry->client_id === $this->project->client_id,
                'running' => $entry->end_time === null,
            ];
        });

        foreach ($rows as $path => $row) {
            $this->assertSame(['title' => true, 'description' => true, 'source' => 'manual', 'billable' => true, 'client' => true, 'running' => true], $row, "Start path {$path} diverged.");
        }
    }

    public function test_every_stop_path_rounds_the_same_way(): void
    {
        $stops = [
            'web' => fn (TimeEntry $entry) => $this->actingAs($this->user)->postJson("/time-entries/{$entry->id}/stop")->assertOk(),
            'cli' => fn (TimeEntry $entry) => $this->artisan('crm:timer-stop', ['entry' => (string) $entry->id])->assertSuccessful(),
            'mcp' => fn (TimeEntry $entry) => CrmServer::tool(TimerStopTool::class, ['entry' => (string) $entry->id])->assertOk(),
            'finish' => fn (TimeEntry $entry) => $this->actingAs($this->user)->post("/day/tasks/{$entry->task_id}/complete"),
        ];

        foreach ([40 => 1, 630 => 11] as $seconds => $minutes) {
            foreach ($stops as $path => $stop) {
                $task = Task::create(['project_id' => $this->project->id, 'name' => "T {$path} {$seconds}", 'source' => 'manual']);
                $entry = TimeEntry::create([
                    'account_id' => $this->project->account_id, 'project_id' => $this->project->id, 'task_id' => $task->id,
                    'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => now()->subSeconds($seconds), 'duration_minutes' => 0,
                ]);

                $stop($entry);

                $this->assertSame($minutes, (int) $entry->fresh()->duration_minutes, "Stop path {$path} after {$seconds}s.");
            }
        }
    }

    public function test_of_two_racing_stops_the_first_end_time_stands(): void
    {
        $task = Task::create(['project_id' => $this->project->id, 'name' => 'Race', 'source' => 'manual']);
        $entry = TimeEntry::create([
            'account_id' => $this->project->account_id, 'project_id' => $this->project->id, 'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => now()->subMinutes(10), 'duration_minutes' => 0,
        ]);
        $late = TimeEntry::findOrFail($entry->id);

        $this->assertTrue($entry->stopNow());
        Carbon::setTestNow(now()->addMinutes(7));
        $this->assertFalse($late->stopNow('too late'));

        $entry->refresh();
        $this->assertSame('2026-09-16 08:00:00', $entry->end_time->toDateTimeString());
        $this->assertSame(10, $entry->duration_minutes);
        $this->assertNotSame('too late', $entry->description);
        $this->actingAs($this->user)->postJson("/time-entries/{$entry->id}/stop")->assertStatus(422);
    }
}

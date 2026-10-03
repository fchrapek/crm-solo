<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\LogTimeTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * log_time over MCP. The load-bearing case is the timezone one: times a caller
 * states are local wall-clock, storage is UTC, and an unconverted Carbon would
 * silently shift the row by the offset.
 */
final class McpLogTimeToolTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Log Test Co']);
        $this->project = $this->client->ensureGeneralProject();
        $this->task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Hero work',
            'source' => 'manual',
        ]);
    }

    public function test_logging_against_a_task_derives_project_and_client(): void
    {
        CrmServer::tool(LogTimeTool::class, ['minutes' => 45, 'task' => 'Hero'])
            ->assertOk()
            ->assertSee('"minutes":45');

        $entry = TimeEntry::sole();
        $this->assertSame($this->task->id, $entry->task_id);
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame($this->client->id, $entry->client_id);
        $this->assertSame($this->account->id, $entry->account_id);
        $this->assertSame(45, $entry->duration_minutes);
        $this->assertTrue($entry->billable);
    }

    public function test_logging_against_a_client_falls_back_to_the_general_project(): void
    {
        CrmServer::tool(LogTimeTool::class, ['minutes' => 60, 'client' => 'Log Test'])
            ->assertOk();

        $entry = TimeEntry::sole();
        $this->assertNull($entry->task_id);
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame('Ad-hoc work', $entry->description);
    }

    public function test_requires_exactly_one_target(): void
    {
        CrmServer::tool(LogTimeTool::class, ['minutes' => 30])
            ->assertHasErrors()
            ->assertSee('invalid_argument');

        CrmServer::tool(LogTimeTool::class, [
            'minutes' => 30,
            'task' => (string) $this->task->id,
            'client' => (string) $this->client->id,
        ])->assertHasErrors();

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_rejects_non_positive_minutes(): void
    {
        CrmServer::tool(LogTimeTool::class, ['minutes' => 0, 'task' => (string) $this->task->id])
            ->assertHasErrors();

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_end_is_read_as_local_wall_clock_and_stored_as_utc(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);

        // 15:30 Warsaw (CEST, +02:00) is 13:30 UTC; 30 minutes earlier is 13:00.
        CrmServer::tool(LogTimeTool::class, [
            'minutes' => 30,
            'task' => (string) $this->task->id,
            'end' => '2026-07-14 15:30',
        ])->assertOk();

        $entry = TimeEntry::sole();
        $this->assertSame('2026-07-14 13:30', $entry->end_time->format('Y-m-d H:i'));
        $this->assertSame('2026-07-14 13:00', $entry->start_time->format('Y-m-d H:i'));
    }

    public function test_non_billable_and_unparseable_end(): void
    {
        CrmServer::tool(LogTimeTool::class, [
            'minutes' => 15,
            'task' => (string) $this->task->id,
            'billable' => false,
        ])->assertOk();

        $this->assertFalse(TimeEntry::sole()->billable);

        CrmServer::tool(LogTimeTool::class, [
            'minutes' => 15,
            'task' => (string) $this->task->id,
            'end' => 'whenever',
        ])->assertHasErrors();

        $this->assertSame(1, TimeEntry::count());
    }

    public function test_ambiguous_task_returns_candidates_without_logging(): void
    {
        Task::create(['project_id' => $this->project->id, 'name' => 'Hero polish', 'source' => 'manual']);

        CrmServer::tool(LogTimeTool::class, ['minutes' => 30, 'task' => 'Hero'])
            ->assertHasErrors()
            ->assertSee('ambiguous_reference');

        $this->assertSame(0, TimeEntry::count());
    }
}

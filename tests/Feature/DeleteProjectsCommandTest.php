<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * projects:delete removes the projects and their tasks, as it says, and the
 * time logged on those tasks stays, billed to the client.
 */
final class DeleteProjectsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_project_deletes_its_tasks_and_keeps_their_time(): void
    {
        $account = Account::create(['name' => 'Acc']);
        $client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Old site']);
        $kept = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Other']);
        $task = Task::create(['project_id' => $project->id, 'name' => 'Header', 'source' => 'manual']);
        $other = Task::create(['project_id' => $kept->id, 'name' => 'Footer', 'source' => 'manual']);
        TimeEntry::create([
            'account_id' => $account->id, 'project_id' => $project->id, 'client_id' => $client->id, 'task_id' => $task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => now()->subHour(), 'end_time' => now(), 'duration_minutes' => 60,
        ]);

        $this->artisan('projects:delete', ['project' => (string) $project->id, '--force' => true])
            ->expectsOutputToContain('Deleted 1 project(s) and 1 task(s)')
            ->assertSuccessful();

        $this->assertNull(Project::find($project->id));
        $this->assertNull(Task::find($task->id));
        $this->assertNotNull(Task::find($other->id));
        $entry = TimeEntry::sole();
        $this->assertNull($entry->task_id);
        $this->assertSame($client->id, $entry->client_id);
        $this->assertSame(60, $entry->duration_minutes);
    }
}

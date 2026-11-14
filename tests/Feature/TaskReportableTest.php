<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientRetainer;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A new task on a retainer client starts reportable, and every way an agent touches a task's time says when it counts nowhere. */
final class TaskReportableTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Project $retained;

    private Project $hourly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Acc']);
        User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $retained = Client::create(['account_id' => $this->account->id, 'name' => 'Abonament', 'type' => 'business']);
        ClientRetainer::create(['account_id' => $this->account->id, 'client_id' => $retained->id, 'monthly_hours' => 6, 'effective_from' => '2026-01-01', 'is_active' => true, 'currency' => 'PLN']);
        $hourly = Client::create(['account_id' => $this->account->id, 'name' => 'Zlecenie', 'type' => 'business']);
        $this->retained = Project::create(['account_id' => $this->account->id, 'client_id' => $retained->id, 'name' => 'Site']);
        $this->hourly = Project::create(['account_id' => $this->account->id, 'client_id' => $hourly->id, 'name' => 'Shop']);
    }

    public function test_a_new_task_starts_reportable_only_while_its_client_has_an_active_retainer(): void
    {
        $writer = app(TaskWriter::class);

        $this->assertTrue($writer->create($this->retained, ['name' => 'Poprawki'])->is_reportable);
        $this->assertFalse($writer->create($this->hourly, ['name' => 'Sklep'])->is_reportable);
        $this->assertFalse($writer->create($this->retained, ['name' => 'Wewnętrzne', 'is_reportable' => false])->is_reportable);
        $this->assertTrue(Task::create(['project_id' => $this->retained->id, 'name' => 'Karta z Trello', 'trello_card_id' => 'abc'])->is_reportable);
    }

    public function test_time_on_a_task_that_is_not_reportable_comes_with_a_warning(): void
    {
        $task = Task::create(['project_id' => $this->hourly->id, 'name' => 'Sklep']);

        $this->artisan('time:log', ['minutes' => 30, '--task' => $task->id])
            ->expectsOutputToContain("WARNING Task {$task->id} is not reportable")->assertSuccessful();
        $this->artisan('crm:timer-start', ['task' => (string) $task->id])
            ->expectsOutputToContain("WARNING Task {$task->id} is not reportable")->assertSuccessful();
        $this->artisan('crm:timer-stop')
            ->expectsOutputToContain("WARNING Task {$task->id} is not reportable")->assertSuccessful();
        $this->artisan('time:log', ['minutes' => 30, '--project' => (string) $this->hourly->id])
            ->expectsOutputToContain('WARNING This time has no task')->assertSuccessful();

        $reportable = Task::create(['project_id' => $this->retained->id, 'name' => 'Poprawki']);
        $this->artisan('time:log', ['minutes' => 30, '--task' => $reportable->id])
            ->doesntExpectOutputToContain('WARNING')->assertSuccessful();
    }

    public function test_the_task_record_says_whether_its_time_counts(): void
    {
        $task = Task::create(['project_id' => $this->hourly->id, 'name' => 'Sklep']);

        $this->artisan('crm:task', ['task' => (string) $task->id])
            ->expectsOutputToContain('NOT reportable: this time does not count in the report or the hours bank')->assertSuccessful();
    }
}

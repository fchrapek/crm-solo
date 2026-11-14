<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ShellTimerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
        $this->task = Task::create(['account_id' => $account->id, 'project_id' => $project->id, 'name' => 'Invoice correction']);
    }

    public function test_every_page_carries_the_running_timer_for_the_shell(): void
    {
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start")->assertSuccessful();

        $this->actingAs($this->user)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('timer.task', 'Invoice correction')
                ->where('timer.client', 'ACME')
                ->has('timer.id')
                ->has('timer.started_at'));
    }

    public function test_the_shell_timer_is_null_while_nothing_runs(): void
    {
        $this->actingAs($this->user)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->where('timer', null));
    }

    public function test_another_accounts_timer_never_reaches_the_shell(): void
    {
        $this->actingAs($this->user)->postJson("/tasks/{$this->task->id}/time-entries/start")->assertSuccessful();
        $other = User::factory()->create(['account_id' => Account::create(['name' => 'Other'])->id, 'owner' => true]);

        $this->actingAs($other)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->where('timer', null));
    }
}

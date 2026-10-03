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
use App\Services\Agent\TodayDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every surface that lists open work reads one definition, so a Trello card
 * the owner has finished stays out of all of them while it waits in Testing.
 */
final class OpenTaskDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Task $open;

    private Task $finished;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::create(['account_id' => $account->id, 'name' => 'ACME', 'type' => 'business']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);

        $this->open = Task::create([
            'project_id' => $project->id, 'trello_card_id' => 'c1', 'source' => 'trello',
            'name' => 'Header still open', 'list_name' => 'Doing', 'priority' => 'high',
        ]);
        $this->finished = Task::create([
            'project_id' => $project->id, 'trello_card_id' => 'c2', 'source' => 'trello',
            'name' => 'Header finished', 'list_name' => 'Testing', 'priority' => 'high',
            'is_completed' => false, 'finished_at' => now()->subHour(),
        ]);
    }

    public function test_the_full_task_list_leaves_out_a_finished_card(): void
    {
        $this->actingAs($this->user)->get('/zadania')
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.0.tasks', fn ($tasks) => collect($tasks)->pluck('name')->all() === ['Header still open'])
            );
    }

    public function test_a_finished_card_cannot_be_picked(): void
    {
        $this->actingAs($this->user)
            ->post('/day/picks', ['task_id' => $this->finished->id, 'date' => now(config('app.display_timezone'))->toDateString()])
            ->assertSessionHasErrors('task_id');
    }

    public function test_today_and_brief_leave_out_a_finished_card(): void
    {
        $attention = collect(app(TodayDigest::class)->build($this->client->account_id)['attention_tasks'])->pluck('id')->all();
        $this->assertSame([$this->open->id], $attention);

        $brief = collect(app(ClientBrief::class)->for($this->client)['open_tasks'])->pluck('id')->all();
        $this->assertSame([$this->open->id], $brief);
    }

    public function test_starting_work_by_name_skips_a_finished_card(): void
    {
        $this->artisan('crm:timer-start', ['task' => 'Header'])->assertSuccessful();

        $this->assertSame($this->open->id, TimeEntry::whereNull('end_time')->sole()->task_id);
    }

    public function test_logging_past_time_by_name_still_finds_a_finished_card(): void
    {
        $this->open->update(['finished_at' => now()]);

        $this->artisan('time:log', ['minutes' => 30, '--task' => 'Header finished'])->assertSuccessful();

        $this->assertSame($this->finished->id, TimeEntry::sole()->task_id);
    }

    public function test_a_card_left_on_done_is_not_open_even_unticked(): void
    {
        $this->open->update(['list_name' => 'Done']);

        $this->assertSame(0, Task::query()->open()->count());
    }

    public function test_a_task_on_an_archived_project_is_not_open_work(): void
    {
        $this->open->project->update(['archived_at' => now()]);

        $this->assertSame(0, Task::query()->open()->count());
        $this->actingAs($this->user)
            ->post('/day/picks', ['task_id' => $this->open->id, 'date' => now(config('app.display_timezone'))->toDateString()])
            ->assertSessionHasErrors('task_id');
        $this->assertSame([], collect(app(TodayDigest::class)->build($this->client->account_id)['attention_tasks'])->pluck('id')->all());
    }

    public function test_a_task_of_a_deleted_client_is_not_open_work(): void
    {
        $this->client->delete();

        $this->assertSame(0, Task::query()->open()->count());
        $this->actingAs($this->user)->get('/zadania')
            ->assertInertia(fn (Assert $page) => $page->where('groups', []));
    }

    public function test_a_task_on_a_project_without_a_client_stays_open_work(): void
    {
        $this->open->project->update(['client_id' => null]);

        $this->assertSame([$this->open->id], Task::query()->open()->pluck('id')->all());
    }
}

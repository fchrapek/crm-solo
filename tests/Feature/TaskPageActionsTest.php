<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the task page's "more" menu and report switch stand on: the props they read and the
 * answers of the endpoints they call, for a CRM task, a card and a settled period.
 */
final class TaskPageActionsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Client $client;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false, 'app.display_timezone' => 'Europe/Warsaw']);
        Carbon::setTestNow('2026-10-08 10:00:00');

        $this->account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Pracownia Mebli']);
        $this->project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Strona WWW']);
        $this->task = Task::create(['project_id' => $this->project->id, 'name' => 'Cennik PDF', 'source' => 'manual', 'list_name' => 'Doing', 'is_reportable' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_page_says_whether_the_task_counts_in_the_report(): void
    {
        $this->page()->assertInertia(fn (Assert $page) => $page->where('task.is_reportable', true)->where('task.is_archived', false));

        $this->actingAs($this->owner)->patchJson(route('tasks.reportable', $this->task), ['is_reportable' => false])
            ->assertOk()
            ->assertExactJson(['is_reportable' => false]);

        $this->page()->assertInertia(fn (Assert $page) => $page->where('task.is_reportable', false));
    }

    public function test_the_edit_form_gets_the_projects_other_live_tasks_as_parents_only_when_it_asks(): void
    {
        $sibling = Task::create(['project_id' => $this->project->id, 'name' => 'Sezon jesienny', 'source' => 'manual']);
        Task::create(['project_id' => $this->project->id, 'name' => 'Stare', 'source' => 'manual', 'archived_at' => now()]);
        $other = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Sklep']);
        Task::create(['project_id' => $other->id, 'name' => 'Koszyk', 'source' => 'manual']);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->missing('parent_options')
            ->reloadOnly('parent_options', fn (Assert $reload) => $reload->where('parent_options', [['id' => $sibling->id, 'name' => 'Sezon jesienny']]))
        );
    }

    public function test_editing_the_project_moves_the_task_with_its_time_to_another_project_of_the_client(): void
    {
        $gallery = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Galeria']);
        $entry = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->project->id, 'client_id' => $this->client->id, 'task_id' => $this->task->id,
            'description' => 'Work', 'start_time' => '2026-10-07 08:00:00', 'end_time' => '2026-10-07 09:00:00', 'duration_minutes' => 60,
        ]);

        $this->actingAs($this->owner)
            ->put("/tasks/{$this->task->id}", ['name' => 'Cennik PDF', 'list_name' => 'Doing', 'project_id' => $gallery->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($gallery->id, $this->task->fresh()->project_id);
        $this->assertSame($gallery->id, $entry->fresh()->project_id);
        $this->assertSame(60, (int) $entry->fresh()->duration_minutes);
    }

    public function test_a_task_cannot_move_to_another_clients_project_from_the_form(): void
    {
        $other = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Fundacja Testowa']);
        $foreign = Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Strona fundacji']);

        $this->actingAs($this->owner)
            ->put("/tasks/{$this->task->id}", ['name' => 'Cennik PDF', 'project_id' => $foreign->id])
            ->assertSessionHasErrors('project_id');

        $this->assertSame($this->project->id, $this->task->fresh()->project_id);
    }

    public function test_the_edit_form_gets_the_clients_projects_only_when_it_asks(): void
    {
        $gallery = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Galeria']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Stary', 'archived_at' => now()]);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->missing('project_options')
            ->reloadOnly('project_options', fn (Assert $reload) => $reload
                ->where('project_options', fn ($options) => collect($options)->pluck('name')->all() === ['Galeria', 'General', 'Strona WWW']
                    && collect($options)->firstWhere('name', 'Galeria')['id'] === $gallery->id))
        );
    }

    public function test_a_logged_time_row_carries_its_entry_for_the_edit_dialog(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'project_id' => $this->project->id, 'task_id' => $this->task->id,
            'source' => 'manual', 'title' => 'Tabele', 'description' => 'Ceny z arkusza', 'billable' => true,
            'start_time' => '2026-10-08 08:00:00', 'end_time' => '2026-10-08 08:42:00', 'duration_minutes' => 42,
        ]);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('activity.rows.0.kind', 'time')
            ->where('activity.rows.0.entry.id', $entry->id)
            ->where('activity.rows.0.entry.task_id', $this->task->id)
            ->where('activity.rows.0.entry.title', 'Tabele')
            ->where('activity.rows.0.entry.billable', true)
            ->where('activity.rows.0.entry.start_time', '2026-10-08T08:00:00+00:00')
            ->where('activity.rows.0.entry.end_time', '2026-10-08T08:42:00+00:00')
        );
    }

    public function test_archiving_keeps_the_page_and_restoring_brings_the_task_back(): void
    {
        $this->actingAs($this->owner)->patchJson(route('tasks.archived', $this->task), ['archived' => true])->assertOk();
        $this->page()->assertInertia(fn (Assert $page) => $page->where('task.is_archived', true));

        $this->actingAs($this->owner)->patchJson(route('tasks.archived', $this->task), ['archived' => false])->assertOk()->assertExactJson(['archived_at' => null]);
        $this->page()->assertInertia(fn (Assert $page) => $page->where('task.is_archived', false));
    }

    public function test_a_deleted_task_is_gone_and_its_client_tab_still_opens(): void
    {
        $this->actingAs($this->owner)->deleteJson(route('tasks.destroy', $this->task))->assertOk();

        $this->actingAs($this->owner)->get(route('tasks.show', $this->task->id))->assertNotFound();
        $this->actingAs($this->owner)->get(route('clients.show', ['client' => $this->client->id, 'tab' => 'tasks']))->assertOk();
    }

    public function test_a_card_is_not_edited_archived_or_deleted_here_but_its_report_flag_is_the_crms(): void
    {
        $card = Task::create(['project_id' => $this->project->id, 'name' => 'Karta', 'source' => 'trello', 'trello_card_id' => 'card-1', 'is_reportable' => false]);

        $this->actingAs($this->owner)->putJson(route('tasks.update', $card), ['name' => 'Inna'])->assertUnprocessable();
        $this->actingAs($this->owner)->patchJson(route('tasks.archived', $card), ['archived' => true])->assertUnprocessable();
        $this->actingAs($this->owner)->deleteJson(route('tasks.destroy', $card))->assertUnprocessable();
        $this->actingAs($this->owner)->patchJson(route('tasks.reportable', $card), ['is_reportable' => true])->assertOk();

        $card->refresh();
        $this->assertSame('Karta', $card->name);
        $this->assertNull($card->archived_at);
        $this->assertTrue($card->is_reportable);
    }

    public function test_the_report_flag_is_refused_with_the_reason_while_a_finalized_report_counts_the_task(): void
    {
        ClientReport::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_FINALIZED, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'contracted_hours' => 10, 'opening_balance_hours' => 0, 'actual_hours' => 1, 'composer_key' => 'manual',
        ]);
        TimeEntry::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'project_id' => $this->project->id, 'task_id' => $this->task->id,
            'source' => 'manual', 'start_time' => '2026-09-10 09:00:00', 'end_time' => '2026-09-10 10:00:00', 'duration_minutes' => 60,
        ]);

        $this->actingAs($this->owner)->patchJson(route('tasks.reportable', $this->task), ['is_reportable' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_reportable' => 'This task counts in the finalized or sent report for September 2026. Reopen the report to change whether it counts.']);

        $this->assertTrue($this->task->fresh()->is_reportable);
    }

    private function page(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->get(route('tasks.show', $this->task))->assertOk();
    }
}

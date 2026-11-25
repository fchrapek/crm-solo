<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\TaskBilledMonth;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\TaskEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settlement\SettlementFixtures;
use Tests\TestCase;

/** The task form's Project field moves a task inside its client: only that client's open time follows, never a figure. */
final class TaskFormProjectMoveTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    private User $member;

    private Client $other;

    private Project $shop;

    private Project $gallery;

    private TimeEntry $acmeEntry;

    private TimeEntry $shopEntry;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        $this->setUpSettlement();
        $this->member = User::factory()->create(['account_id' => $this->account->id, 'owner' => false]);

        $this->acmeEntry = $this->entry('2026-10-05 08:00:00', 60);
        TaskBilledMonth::create(['task_id' => $this->task->id, 'client_id' => $this->client->id, 'period' => '2026-10', 'billed_minutes' => 180]);

        $this->other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $this->shop = Project::create(['account_id' => $this->account->id, 'client_id' => $this->other->id, 'name' => 'Sklep']);
        $this->gallery = Project::create(['account_id' => $this->account->id, 'client_id' => $this->other->id, 'name' => 'Galeria']);

        // Moved to the other client without its time, then worked on there.
        app(TaskEditor::class)->edit($this->account->id, (string) $this->task->id, ['project' => (string) $this->shop->id]);
        $this->shopEntry = TimeEntry::create([
            'account_id' => $this->account->id, 'project_id' => $this->shop->id, 'client_id' => $this->other->id, 'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-10-12 08:00:00', 'end_time' => '2026-10-12 09:30:00', 'duration_minutes' => 90,
        ]);
    }

    public function test_a_member_moving_the_task_between_the_clients_projects_moves_only_that_clients_time(): void
    {
        $figures = $this->figures();
        $acme = $this->acmeEntry->fresh()->only(['project_id', 'client_id', 'task_id', 'duration_minutes']);

        $this->move($this->member)->assertSessionHasNoErrors();

        $this->assertSame($this->gallery->id, $this->task->fresh()->project_id);
        $this->assertSame($this->gallery->id, (int) $this->shopEntry->fresh()->project_id);
        $this->assertSame($this->other->id, (int) $this->shopEntry->fresh()->client_id);
        $this->assertSame($acme, $this->acmeEntry->fresh()->only(['project_id', 'client_id', 'task_id', 'duration_minutes']));
        $this->assertSame($figures, $this->figures());
    }

    public function test_the_owner_moving_the_task_from_the_form_leaves_another_clients_time_and_figures_too(): void
    {
        $figures = $this->figures();

        $this->move($this->user)->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('Task updated. :count time entries stay with another client.', ['count' => 1]));

        $this->assertSame($this->project->id, (int) $this->acmeEntry->fresh()->project_id);
        $this->assertSame($this->client->id, (int) $this->acmeEntry->fresh()->client_id);
        $this->assertSame($figures, $this->figures());
    }

    public function test_the_clients_time_in_a_settled_month_stays_on_the_old_project(): void
    {
        $this->closure('2026-10', 0, 6, 1.5, client: $this->other);

        $this->move($this->member)->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('Task updated. :count time entries stay on the old project: their period is settled.', ['count' => 1]));

        $this->assertSame($this->gallery->id, $this->task->fresh()->project_id);
        $this->assertSame($this->shop->id, (int) $this->shopEntry->fresh()->project_id);
    }

    public function test_the_agent_move_with_its_time_still_brings_the_other_clients_time_and_figures(): void
    {
        app(TaskEditor::class)->edit($this->account->id, (string) $this->task->id, ['project' => (string) $this->gallery->id, 'move_time' => true]);

        $this->assertSame($this->gallery->id, (int) $this->acmeEntry->fresh()->project_id);
        $this->assertSame([$this->other->id], TaskBilledMonth::query()->pluck('client_id')->map(fn ($id): int => (int) $id)->all());
    }

    private function move(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->put("/tasks/{$this->task->id}", ['name' => 'Feature', 'list_name' => 'Doing', 'project_id' => $this->gallery->id]);
    }

    /** @return list<array<string, mixed>> */
    private function figures(): array
    {
        return TaskBilledMonth::query()->orderBy('id')->get(['task_id', 'client_id', 'period', 'billed_minutes'])->toArray();
    }
}

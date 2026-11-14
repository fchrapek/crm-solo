<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Models\Account;
use App\Models\ClientBilledMonth;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBilledMonth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BilledTimeEndpointsTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(6, '2026-01-01');
        $this->entry('2026-09-10 08:00:00', 100);
    }

    public function test_the_dialog_reads_both_values_per_task_and_the_balance_they_lead_to(): void
    {
        $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/billed/2026-09")
            ->assertOk()
            ->assertJsonMissingPath('enabled')
            ->assertJsonMissingPath('rounding_minutes')
            ->assertJsonPath('real', 1.67)
            ->assertJsonPath('billed', 1.67)
            ->assertJsonPath('tasks.0.task_id', $this->task->id)
            ->assertJsonPath('tasks.0.task', $this->task->name)
            ->assertJsonPath('tasks.0.project', $this->project->name)
            ->assertJsonPath('tasks.0.stated', null)
            ->assertJsonPath('opening', 0)
            ->assertJsonPath('contracted', 6)
            ->assertJsonPath('closing', 4.33)
            ->assertJsonPath('refusal', null);
    }

    public function test_a_task_figure_is_set_with_its_note_and_cleared_back_to_real_time(): void
    {
        $before = $this->entryRows();
        $url = "/clients/{$this->client->id}/billed/2026-09/tasks/{$this->task->id}";
        $from = "/clients/{$this->client->id}/rozliczenia";

        $this->actingAs($this->user)->from($from)->put($url, ['hours' => 2.5, 'note' => 'Testy ręczne'])
            ->assertRedirect($from)->assertSessionHas('success', 'Task billed: 2.5 h, September 2026 in total: 2.5 h.');
        $this->assertSame([150, 'Testy ręczne', $this->user->id], [TaskBilledMonth::query()->sole()->billed_minutes, TaskBilledMonth::query()->sole()->note, TaskBilledMonth::query()->sole()->updated_by_user_id]);

        $this->actingAs($this->user)->put($url, ['hours' => -1])->assertSessionHasErrors('hours');
        $this->actingAs($this->user)->put($url, [])->assertSessionHasErrors('hours');

        $this->actingAs($this->user)->from($from)->delete($url)->assertSessionHas('success', 'Task billed: 1.67 h, September 2026 in total: 1.67 h.');
        $this->assertSame(0, TaskBilledMonth::query()->count());
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_save_without_a_note_keeps_the_stored_note_and_an_empty_one_clears_it(): void
    {
        $url = "/clients/{$this->client->id}/billed/2026-09/tasks/{$this->task->id}";
        $before = $this->entryRows();
        $this->actingAs($this->user)->put($url, ['hours' => 2.5, 'note' => 'Rabat']);
        $this->actingAs($this->user)->put($url, ['hours' => 3]);
        $this->assertSame('Rabat', TaskBilledMonth::query()->sole()->note);

        $this->actingAs($this->user)->put($url, ['hours' => 3, 'note' => '']);
        $this->assertNull(TaskBilledMonth::query()->sole()->note);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_a_task_of_another_client_and_a_bad_month_are_not_found(): void
    {
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep']);
        $foreign = Task::create(['project_id' => $project->id, 'name' => 'Obca', 'is_reportable' => true]);

        $this->actingAs($this->user)->put("/clients/{$this->client->id}/billed/2026-09/tasks/{$foreign->id}", ['hours' => 3])->assertNotFound();
        $this->actingAs($this->user)->delete("/clients/{$this->client->id}/billed/2026-09/tasks/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->user)->put("/clients/{$this->client->id}/billed/2026-13/tasks/{$this->task->id}", ['hours' => 3])->assertNotFound();
        $this->assertSame(0, TaskBilledMonth::query()->count());
    }

    public function test_the_month_total_and_setting_endpoints_are_gone(): void
    {
        $this->actingAs($this->user)->put("/clients/{$this->client->id}/billed/2026-09", ['hours' => 3, 'task_id' => $this->task->id])->assertStatus(405);
        $this->actingAs($this->user)->delete("/clients/{$this->client->id}/billed/2026-09")->assertStatus(405);
        $this->actingAs($this->user)->patch("/clients/{$this->client->id}/billed-settings", ['billed_time' => true, 'billed_rounding_minutes' => 30])->assertNotFound();

        $this->assertSame(0, TaskBilledMonth::query()->count() + ClientBilledMonth::query()->count());
    }

    public function test_another_account_and_an_account_without_billing_cannot_reach_it(): void
    {
        $url = "/clients/{$this->client->id}/billed/2026-09/tasks/{$this->task->id}";
        $other = Account::create(['name' => 'Other']);
        $stranger = User::factory()->create(['account_id' => $other->id, 'owner' => true]);
        $this->actingAs($stranger)->getJson("/clients/{$this->client->id}/billed/2026-09")->assertNotFound();
        $this->actingAs($stranger)->put($url, ['hours' => 1])->assertNotFound();

        $this->account->update(['settings' => [...$this->account->settings, 'features' => ['billing' => false]]]);
        $this->actingAs($this->user)->getJson("/clients/{$this->client->id}/billed/2026-09")->assertNotFound();
        $this->actingAs($this->user)->put($url, ['hours' => 1])->assertNotFound();

        $this->assertSame(0, TaskBilledMonth::query()->count());
    }

    public function test_the_billing_tab_carries_both_values_per_month_for_every_client(): void
    {
        $this->actingAs($this->user)->put("/clients/{$this->client->id}/billed/2026-09/tasks/{$this->task->id}", ['hours' => 2.5]);

        $props = $this->actingAs($this->user)->get("/clients/{$this->client->id}/rozliczenia")->assertOk()->viewData('page')['props'];
        $billing = is_callable($props['billing']) ? $props['billing']() : $props['billing'];
        $this->assertArrayNotHasKey('billed_time', $billing);
        $september = collect($props['billing_ledger']['rows'])->firstWhere('period', '2026-09');
        $this->assertSame(['real' => 1.67, 'billed' => 2.5, 'tasks' => [['task_id' => $this->task->id, 'real' => 1.67, 'stated' => 2.5, 'billed' => 2.5]], 'note' => null], $september['billed']);
        $this->assertTrue($september['billed_editable']);
        $this->assertEquals(2.5, $september['used']);
    }

    public function test_a_client_without_a_retainer_gets_its_months_in_both_values(): void
    {
        $order = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $this->entry('2026-09-10 08:00:00', 70, client: $order);

        $billing = $this->actingAs($this->user)->get("/clients/{$order->id}/rozliczenia")->assertOk()->viewData('page')['props']['billing'];
        $billing = is_callable($billing) ? $billing() : $billing;

        $this->assertSame('order', $billing['mode']);
        $this->assertCount(1, $billing['billed_months']);
        $this->assertSame('2026-09', $billing['billed_months'][0]['period']);
        $this->assertSame(1.17, $billing['billed_months'][0]['billed']['billed']);
        $this->assertTrue($billing['billed_months'][0]['billed_editable']);
        $this->actingAs($this->user)->getJson("/clients/{$order->id}/billed/2026-09")->assertJsonPath('has_retainer', false)->assertJsonPath('billed', 1.17);
    }

    public function test_the_report_editor_gets_both_values_for_every_client(): void
    {
        $report = $this->report('2026-09', 0, 6, 2);

        $this->actingAs($this->user)->get("/clients/{$this->client->id}/reports/{$report->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.billed.real', 1.67)->where('report.billed.billed', 2));
    }

    /** @return list<array<string, mixed>> */
    private function entryRows(): array
    {
        return DB::table('time_entries')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\ClientBilledMonth;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskBilledMonth;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Settlement\SettlementFixtures;
use Tests\TestCase;

/** Agents read a month in both values and change only the billed one, through BilledTime, with write:billing, audited. */
final class AgentBilledTimeTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement();
        $this->retainer(6, '2026-01-01');
        $this->entry('2026-09-10 08:00:00', 100);
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::BILLING]);
    }

    public function test_billed_reads_both_values_of_a_month_with_its_tasks(): void
    {
        $this->verb('billed', ['Acme', '--month=2026-09', '--json'])->assertHeader('X-Crm-Exit', '0')
            ->assertJsonPath('period', '2026-09')->assertJsonPath('real', 1.67)->assertJsonPath('billed', 1.67)->assertJsonMissingPath('rounding_minutes')
            ->assertJsonPath('tasks.0.task_id', $this->task->id)->assertJsonPath('tasks.0.task', 'Feature')->assertJsonPath('tasks.0.untrusted', ['task', 'project']);

        $this->verb('billed', ['Acme', '--month=2026-09'])->assertHeader('X-Crm-Exit', '0')
            ->assertSee('real 1.67 h, billed 1.67 h (each task its stated figure, else its real time)')
            ->assertSee("#{$this->task->id} Feature: real 1.67 h, billed 1.67 h");
    }

    public function test_task_billed_reads_both_values_and_sets_and_clears_the_figure(): void
    {
        $before = $this->entryRows();

        $this->verb('task-billed', [(string) $this->task->id, '--json'])->assertHeader('X-Crm-Exit', '0')
            ->assertJsonPath('period', '2026-09')->assertJsonPath('real', 1.67)->assertJsonPath('stated', null)->assertJsonPath('billed', 1.67)
            ->assertJsonPath('reportable', true)->assertJsonPath('month.closing', 4.33)->assertJsonPath('refusal', null);

        $this->verb('task-billed', [(string) $this->task->id, '--hours=3', '--note=Testy ręczne', '--json'])->assertHeader('X-Crm-Exit', '0')
            ->assertJsonPath('message', 'Task billed: 3.0 h, September 2026 in total: 3.0 h.')
            ->assertJsonPath('task.stated', 3)->assertJsonPath('task.month.billed', 3)->assertJsonPath('task.note', 'Testy ręczne');
        $this->assertSame(180, TaskBilledMonth::query()->sole()->billed_minutes);

        $this->verb('task-billed', [(string) $this->task->id])->assertHeader('X-Crm-Exit', '0')->assertSee('real 1.67 h, billed 3 h (stated)');
        $this->verb('task-billed', [(string) $this->task->id, '--clear', '--json'])->assertHeader('X-Crm-Exit', '0')->assertJsonPath('task.billed', 1.67);
        $this->assertSame(0, TaskBilledMonth::query()->count());

        $audit = AgentAuditEvent::query()->where('target_type', 'task_billed_months')->get();
        $this->assertSame(['crm:task-billed'], $audit->pluck('verb')->unique()->values()->all());
        $this->assertSame($before, $this->entryRows());
    }

    public function test_the_month_total_and_setting_verbs_are_gone(): void
    {
        $this->verb('billed-set', ['Acme', '--month=2026-09', '--hours=3', '--task=Feature'])->assertNotFound()->assertSee('is not available in remote mode');
        $this->verb('billed-settings', ['Acme', '--on'])->assertNotFound();
        $this->verb('task-billed', [(string) $this->task->id, '--hours=3', '--json'])->assertHeader('X-Crm-Exit', '0');
    }

    public function test_task_billed_on_a_task_that_is_not_reportable_says_why(): void
    {
        $this->task->update(['is_reportable' => false]);

        $this->verb('task-billed', [(string) $this->task->id, '--json'])->assertJsonPath('refusal_code', 'not_reportable');
        $this->verb('task-billed', [(string) $this->task->id, '--hours=3', '--json'])->assertHeader('X-Crm-Exit', '1')->assertJsonPath('error', 'not_reportable');
        $this->assertSame(0, TaskBilledMonth::query()->count());
    }

    public function test_task_done_with_billed_finishes_then_bills_and_a_refused_bill_still_finishes(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::TASKS, AgentAbilities::BILLING]);
        $before = $this->entryRows();

        $this->verb('task-done', [(string) $this->task->id, '--billed=3', '--json'])->assertHeader('X-Crm-Exit', '0')
            ->assertJsonPath('reportable', true)->assertJsonPath('warnings', [])->assertJsonPath('billed.task.stated', 3);
        $this->assertNotNull($this->task->fresh()->finished_at);
        $this->assertSame(180, TaskBilledMonth::query()->sole()->billed_minutes);

        $other = Task::create(['project_id' => $this->project->id, 'name' => 'Poprawki', 'is_reportable' => false]);
        $this->verb('task-done', [(string) $other->id, '--billed=1'])->assertHeader('X-Crm-Exit', '1')
            ->assertSee("WARNING Task {$other->id} is not reportable")
            ->assertSee('ERROR billed hours not saved, the task is finished');
        $this->assertNotNull($other->fresh()->finished_at);
        $this->assertSame(1, TaskBilledMonth::query()->count());
        $this->assertSame($before, $this->entryRows());
    }

    public function test_moving_a_task_with_its_time_moves_its_open_figures_and_leaves_a_settled_one(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::TASKS, AgentAbilities::BILLING]);
        $this->entry('2026-10-05 08:00:00', 60);
        $this->verb('task-billed', [(string) $this->task->id, '--month=2026-09', '--hours=2'])->assertHeader('X-Crm-Exit', '0');
        $this->verb('task-billed', [(string) $this->task->id, '--month=2026-10', '--hours=3'])->assertHeader('X-Crm-Exit', '0');
        $this->closure('2026-09', 0, 6, 2);
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep']);

        $before = TaskBilledMonth::query()->orderBy('period')->get(['period', 'client_id'])->toArray();
        $this->verb('task-edit', [(string) $this->task->id, '--project=Sklep', '--move-time', '--json'])->assertHeader('X-Crm-Exit', '0')
            ->assertJsonPath('billed_stayed', ['2026-09']);

        $this->assertSame([['period' => '2026-09', 'client_id' => $this->client->id], ['period' => '2026-10', 'client_id' => $other->id]], TaskBilledMonth::query()->orderBy('period')->get(['period', 'client_id'])->toArray());
        $this->assertNotSame($before, TaskBilledMonth::query()->orderBy('period')->get(['period', 'client_id'])->toArray());
        $this->verb('billed', [(string) $other->id, '--month=2026-10', '--json'])->assertJsonPath('real', 1)->assertJsonPath('billed', 3);
        $this->verb('billed', ['Acme', '--month=2026-10', '--json'])->assertJsonPath('real', 0)->assertJsonPath('billed', 0);
    }

    public function test_moving_a_task_moves_every_open_figure_with_its_time(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::TASKS, AgentAbilities::BILLING]);
        $this->entry('2026-10-05 08:00:00', 60);
        $this->verb('task-billed', [(string) $this->task->id, '--month=2026-09', '--hours=2'])->assertHeader('X-Crm-Exit', '0');
        $this->verb('task-billed', [(string) $this->task->id, '--month=2026-10', '--hours=3'])->assertHeader('X-Crm-Exit', '0');
        $this->report('2026-09', 0, 6, 2);
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep']);

        $this->verb('task-edit', [(string) $this->task->id, '--project=Sklep', '--move-time', '--json'])->assertHeader('X-Crm-Exit', '0')->assertJsonPath('billed_stayed', []);

        $this->assertSame([$other->id, $other->id], TaskBilledMonth::query()->orderBy('period')->pluck('client_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_moving_time_that_carries_billed_figures_needs_write_billing(): void
    {
        $this->entry('2026-10-05 08:00:00', 60);
        $this->verb('task-billed', [(string) $this->task->id, '--month=2026-10', '--hours=3'])->assertHeader('X-Crm-Exit', '0');
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep']);
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::TASKS]);

        $before = $this->entryRows();
        $this->verb('task-edit', [(string) $this->task->id, '--project=Sklep', '--move-time', '--json'])->assertHeader('X-Crm-Exit', '1')->assertJsonPath('error', 'missing_ability');
        $this->assertSame($before, $this->entryRows());

        $this->assertSame($this->project->id, $this->task->fresh()->project_id);
        $this->assertSame([$this->client->id], TaskBilledMonth::query()->pluck('client_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_a_move_refuses_when_the_task_has_figures_on_both_clients_for_a_month(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::TASKS, AgentAbilities::BILLING]);
        $this->entry('2026-10-05 08:00:00', 60);
        $this->verb('task-billed', [(string) $this->task->id, '--month=2026-10', '--hours=3'])->assertHeader('X-Crm-Exit', '0');
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $shop = Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep']);
        TaskBilledMonth::create(['task_id' => $this->task->id, 'client_id' => $other->id, 'period' => '2026-10', 'billed_minutes' => 120]);

        $before = $this->entryRows();
        $this->verb('task-edit', [(string) $this->task->id, '--project=Sklep', '--move-time', '--json'])->assertHeader('X-Crm-Exit', '1')->assertJsonPath('periods', ['2026-10']);

        $this->assertSame($this->project->id, $this->task->fresh()->project_id);
        $this->assertNotSame($shop->id, $this->task->fresh()->project_id);
        $this->assertSame($before, $this->entryRows());
    }

    public function test_task_billed_reads_real_time_for_the_client_of_its_figures(): void
    {
        $this->entry('2026-10-05 08:00:00', 60);
        $other = $this->account->clients()->create(['name' => 'Kawiarnia', 'currency' => 'PLN']);
        $this->task->update(['project_id' => Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Sklep'])->id]);

        $this->verb('task-billed', [(string) $this->task->id, '--month=2026-10', '--json'])->assertJsonPath('client_id', $other->id)->assertJsonPath('real', 0);
    }

    public function test_task_reads_say_whether_a_task_counts_and_an_empty_billable_task_bills_zero(): void
    {
        $empty = Task::create(['project_id' => $this->project->id, 'name' => 'Konsultacja', 'is_reportable' => true]);

        $this->verb('task-billed', [(string) $empty->id, '--month=2026-10', '--json'])->assertJsonPath('real', 0)->assertJsonPath('billed', 0);
        $this->verb('tasks', ['--client=Acme', '--json'])->assertHeader('X-Crm-Exit', '0')->assertJsonPath('tasks.0.reportable', true);
        $this->verb('task', [(string) $this->task->id, '--json'])->assertJsonPath('time.reportable_minutes', 100)->assertJsonPath('reportable', true);
    }

    public function test_reading_needs_only_read_and_billing_a_task_needs_write_billing(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::TASKS]);

        $this->verb('task-billed', [(string) $this->task->id, '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->verb('task-billed', [(string) $this->task->id, '--hours=3'])->assertForbidden();
        $this->verb('task-done', [(string) $this->task->id, '--billed=3'])->assertForbidden();
        $this->assertNull($this->task->fresh()->finished_at);

        $this->mcp('tools/call', ['name' => 'task_done', 'arguments' => ['task' => $this->task->id, 'billed_hours' => 3]])
            ->assertOk()->assertSee('missing_ability');
        $this->assertNotNull($this->task->fresh()->finished_at);
        $this->assertSame(0, TaskBilledMonth::query()->count());
    }

    public function test_without_a_month_billed_acts_on_the_oldest_month_still_open(): void
    {
        $this->closure('2026-09', 0, 6, 2);

        $this->verb('billed', ['Acme', '--json'])->assertHeader('X-Crm-Exit', '0')->assertJsonPath('period', '2026-10');
    }

    public function test_report_create_with_hours_other_than_the_task_sum_is_refused(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::REPORTS]);
        $this->verb('report-create', ['Acme', '--period=2026-09', '--hours=4', '--composer=structured_list', '--locale=en', '--json'])->assertHeader('X-Crm-Exit', '1')
            ->assertSee('the sum of its tasks');
        $this->verb('report-create', ['Acme', '--period=2026-09', '--hours=4', '--composer=structured_list', '--json'])->assertHeader('X-Crm-Exit', '1')
            ->assertSee('to suma zadań');
        $this->verb('report-create', ['Acme', '--period=2026-09', '--hours=1.67', '--composer=structured_list', '--json'])->assertHeader('X-Crm-Exit', '0');

        $this->assertSame(0, ClientBilledMonth::query()->count() + TaskBilledMonth::query()->count());
    }

    public function test_writing_needs_write_billing_and_stays_inside_the_account(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::REPORTS]);
        $this->verb('billed', ['Acme', '--json'])->assertHeader('X-Crm-Exit', '0');
        $this->verb('task-billed', [(string) $this->task->id, '--hours=1'])->assertForbidden();

        $other = Account::create(['name' => 'Other']);
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::BILLING], User::factory()->create(['account_id' => $other->id, 'owner' => true]));
        $this->verb('task-billed', [(string) $this->task->id, '--hours=1'])->assertHeader('X-Crm-Exit', '1');

        $this->assertSame(0, ClientBilledMonth::query()->count() + TaskBilledMonth::query()->count());
    }

    public function test_the_mcp_tools_read_and_write_the_same_way_and_say_to_confirm_first(): void
    {
        $tools = collect($this->mcp('tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        $this->assertStringContainsString('wait for the owner', $tools['task_billed_set']['description']);
        $this->assertFalse($tools->has('billed_time_set'));
        $this->assertFalse($tools->has('billed_time_settings'));

        $this->mcp('tools/call', ['name' => 'billed_time', 'arguments' => ['client' => 'Acme', 'month' => '2026-09']])
            ->assertOk()->assertJsonPath('result.isError', false)->assertSee('\"billed\":1.67', false);
        $this->mcp('tools/call', ['name' => 'task_billed_set', 'arguments' => ['task' => (string) $this->task->id, 'hours' => 3]])
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->mcp('tools/call', ['name' => 'task_billed', 'arguments' => ['task' => (string) $this->task->id]])
            ->assertOk()->assertJsonPath('result.isError', false)->assertSee('\"stated\":3', false);

        $this->assertSame(180, TaskBilledMonth::query()->sole()->billed_minutes);
    }

    /** @return list<array<string, mixed>> */
    private function entryRows(): array
    {
        return DB::table('time_entries')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    /** @param  list<string>  $abilities */
    private function token(array $abilities, ?User $user = null): string
    {
        $user ??= $this->user;
        $issued = $user->createToken('agent', $abilities, now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $user->account_id])->save();

        return $issued->plainTextToken;
    }

    /** @param  list<string>  $args */
    private function verb(string $verb, array $args = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->plain])->post('/agent/verb', ['verb' => $verb, 'args' => $args]);
    }

    /** @param  array<string, mixed>  $params */
    private function mcp(string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->plain, 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }
}

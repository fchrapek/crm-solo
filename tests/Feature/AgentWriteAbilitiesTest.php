<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\AgentToken;
use App\Models\Client;
use App\Models\DayPick;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The new writes over the hosted bridge: a read-only token reads but never
 * writes, `write:clients` is its own group that older tokens get only when the
 * owner grants it, and every write is audited in the token's account.
 */
final class AgentWriteAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private const array WRITES = [
        'task-create' => ['--name=x', '--client=Bridge Co'],
        'task-edit' => ['TASK', '--name=y'],
        'plan' => ['TASK'],
        'unplan' => ['TASK'],
        'client-create' => ['--name=New Co'],
        'project-create' => ['--client=Bridge Co', '--name=Shop'],
    ];

    private User $owner;

    private Client $client;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00');
        $account = Account::factory()->create();
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Bridge Co', 'month_close_type' => 'maintenance']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $this->task = Task::create(['project_id' => $project->id, 'name' => 'Footer', 'source' => 'manual', 'list_name' => 'To-Do']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_read_token_reads_the_new_lists_and_the_plan_but_writes_nothing(): void
    {
        $plain = $this->token([AgentAbilities::READ]);

        $this->verb($plain, 'tasks', ['--json'])->assertOk()->assertHeader('X-Crm-Exit', '0')->assertSee('Footer');
        $this->verb($plain, 'clients', ['--json'])->assertOk()->assertHeader('X-Crm-Exit', '0')->assertSee('Bridge Co');
        $this->verb($plain, 'plan', ['--day=tomorrow', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');
        $this->verb($plain, 'month-close:tick', [(string) $this->client->id, 'list', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');

        foreach (self::WRITES as $verb => $args) {
            $this->verb($plain, $verb, $this->withTask($args))->assertForbidden();
        }
        $this->verb($plain, 'plan', ['--day', 'tomorrow', (string) $this->task->id])->assertForbidden();

        $this->assertSame(1, Task::query()->count());
        $this->assertSame('Footer', $this->task->fresh()->name);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(0, DayPick::query()->count());
        $this->assertSame(0, Project::query()->where('name', 'Shop')->count());
    }

    public function test_a_read_token_sees_the_read_tools_only_and_cannot_call_a_write_tool(): void
    {
        $plain = $this->token([AgentAbilities::READ]);

        $tools = collect($this->mcp($plain, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertContains('tasks', $tools);
        $this->assertContains('clients', $tools);
        foreach (['task_create', 'task_edit', 'day_plan', 'day_unplan', 'client_create', 'project_create'] as $write) {
            $this->assertNotContains($write, $tools);
        }

        $this->mcp($plain, 'tools/call', ['name' => 'client_create', 'arguments' => ['name' => 'New Co']])->assertJsonPath('error.code', -32602);
        $this->mcp($plain, 'tools/call', ['name' => 'task_create', 'arguments' => ['name' => 'x', 'client' => 'Bridge']])->assertJsonPath('error.code', -32602);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, Task::query()->count());
    }

    public function test_a_tasks_token_plans_and_edits_but_needs_write_clients_to_add_a_client(): void
    {
        $plain = $this->token([AgentAbilities::READ, AgentAbilities::TASKS]);

        $this->verb($plain, 'task-create', ['--name=New task', '--client=Bridge', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');
        $this->verb($plain, 'plan', [(string) $this->task->id, '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');
        $this->verb($plain, 'client-create', ['--name=New Co'])->assertForbidden()->assertSee('write:clients');
        $this->verb($plain, 'project-create', ['--client=Bridge', '--name=Shop'])->assertForbidden()->assertSee('write:clients');

        $this->assertSame(1, DayPick::query()->count());
        $audit = AgentAuditEvent::query()->where('target_type', 'day_picks')->sole();
        $this->assertSame(['cli-remote', 'crm:plan', 'created'], [$audit->via, $audit->verb, $audit->action]);
    }

    public function test_a_token_issued_before_write_clients_lacks_it_until_the_owner_grants_it(): void
    {
        $plain = $this->token([AgentAbilities::READ, AgentAbilities::NOTES, AgentAbilities::TASKS, AgentAbilities::TIME, AgentAbilities::LEADS, AgentAbilities::MONTH_CLOSE]);
        $token = AgentToken::query()->sole();
        $this->verb($plain, 'client-create', ['--name=New Co'])->assertForbidden();
        $expires = $token->expires_at->toDateTimeString();

        $this->assertSame(0, Artisan::call('agent-tokens:grant', ['id' => (string) $token->id, '--ability' => ['write:clients']]));

        $token->refresh();
        $this->assertContains(AgentAbilities::CLIENTS, $token->abilities);
        $this->assertSame($expires, $token->expires_at->toDateTimeString());
        $this->verb($plain, 'client-create', ['--name=New Co', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');
        $audit = AgentAuditEvent::query()->where('target_type', 'clients')->sole();
        $this->assertSame([$token->id, 'crm:client-create'], [$audit->token_id, $audit->verb]);
    }

    public function test_a_clients_token_adds_a_project_over_the_verb_endpoint_and_mcp_and_both_are_audited(): void
    {
        $plain = $this->token([AgentAbilities::READ, AgentAbilities::CLIENTS]);
        $token = AgentToken::query()->sole();

        $this->verb($plain, 'project-create', ['--client=Bridge', '--name=Shop', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');
        $tools = collect($this->mcp($plain, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertContains('project_create', $tools);
        $this->mcp($plain, 'tools/call', ['name' => 'project_create', 'arguments' => ['client' => (string) $this->client->id, 'name' => 'Blog']])
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->verb($plain, 'project-create', ['--client=Bridge', '--name=shop', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '1')->assertSee('possible_duplicate');

        $this->assertSame(['Site', 'Shop', 'Blog'], Project::query()->where('client_id', $this->client->id)->where('name', '!=', 'General')->orderBy('id')->pluck('name')->all());
        $audit = AgentAuditEvent::query()->where('target_type', 'projects')->orderBy('id')->get();
        $this->assertSame(
            [['cli-remote', 'crm:project-create', $token->id], ['mcp-web', 'project_create', $token->id]],
            $audit->map(fn (AgentAuditEvent $event): array => [$event->via, $event->verb, $event->token_id])->all(),
        );
    }

    public function test_write_at_issue_now_includes_clients_and_a_grant_refuses_bad_input(): void
    {
        $this->assertContains(AgentAbilities::CLIENTS, AgentAbilities::normalise(['read', 'write']));

        $plain = $this->token([AgentAbilities::READ]);
        $token = AgentToken::query()->where('token', hash('sha256', explode('|', $plain, 2)[1]))->sole();
        $this->assertSame(1, Artisan::call('agent-tokens:grant', ['id' => (string) $token->id, '--ability' => ['write:everything']]));
        $this->assertSame(1, Artisan::call('agent-tokens:grant', ['id' => '999999', '--ability' => ['write:clients']]));
        $token->forceFill(['revoked_at' => now()])->save();
        $this->assertSame(1, Artisan::call('agent-tokens:grant', ['id' => (string) $token->id, '--ability' => ['write:clients']]));
        $this->assertSame([AgentAbilities::READ], $token->fresh()->abilities);
    }

    public function test_a_read_token_reads_the_month_close_reports_and_settlement_preview(): void
    {
        $plain = $this->token([AgentAbilities::READ]);

        $this->verb($plain, 'month-close', ['--json'])->assertOk()->assertHeader('X-Crm-Exit', '0')->assertSee('"period":"2026-09"', false);
        $this->verb($plain, 'reports', ['Bridge', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0')->assertSee('"total":0', false);
        $this->verb($plain, 'month-settle', ['Bridge', '2026-09', '--preview', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0')->assertSee('"hash":', false);
        $tools = collect($this->mcp($plain, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        foreach (['month_close_overview', 'report', 'reports', 'month_settlement_preview'] as $tool) {
            $this->assertContains($tool, $tools);
        }
    }

    public function test_the_tool_list_holds_every_tool_on_one_page(): void
    {
        $result = $this->mcp($this->token(AgentAbilities::ALL), 'tools/list')->assertOk()->json('result');

        $this->assertCount(count(AgentAbilities::TOOLS), $result['tools']);
        $this->assertArrayNotHasKey('nextCursor', $result);
    }

    public function test_time_entries_read_with_read_and_edits_and_splits_need_write_time(): void
    {
        $entry = TimeEntry::create([
            'account_id' => $this->client->account_id, 'project_id' => $this->task->project_id, 'client_id' => $this->client->id, 'task_id' => $this->task->id,
            'source' => TimeEntry::SOURCE_MANUAL, 'start_time' => '2026-10-06 08:00:00', 'end_time' => '2026-10-06 09:00:00', 'duration_minutes' => 60,
        ]);
        $other = Task::create(['project_id' => $this->task->project_id, 'name' => 'Header', 'source' => 'manual', 'list_name' => 'To-Do']);
        $read = $this->token([AgentAbilities::READ]);

        $this->verb($read, 'time-entries', ['--json', '--day=2026-10-06'])->assertOk()->assertHeader('X-Crm-Exit', '0')->assertSee('"id":'.$entry->id, false);
        $this->verb($read, 'time-edit', [(string) $entry->id, '--minutes=30'])->assertForbidden()->assertSee('write:time');
        $this->verb($read, 'time-split', [(string) $entry->id, '--part='.$other->id.':30'])->assertForbidden();
        $tools = collect($this->mcp($read, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertContains('time_entries', $tools);
        $this->assertNotContains('time_edit', $tools);
        $this->assertNotContains('time_split', $tools);
        $this->assertSame(60, $entry->fresh()->duration_minutes);

        $time = $this->token([AgentAbilities::READ, AgentAbilities::TIME]);
        $this->verb($time, 'time-split', [(string) $entry->id, '--part='.$other->id.':20:Header copy', '--part='.$this->task->id.':40', '--json'])
            ->assertOk()->assertHeader('X-Crm-Exit', '0');

        $this->assertSame([20, 40], TimeEntry::query()->orderBy('start_time')->pluck('duration_minutes')->all());
        $audit = AgentAuditEvent::query()->where('target_type', 'time_entries')->get();
        $this->assertSame([['cli-remote', 'crm:time-split']], $audit->map(fn (AgentAuditEvent $event): array => [$event->via, $event->verb])->unique()->values()->all());
        $this->assertCount(2, $audit);
    }

    public function test_a_token_creates_and_lists_only_inside_its_own_account(): void
    {
        $other = Account::factory()->create();
        $stranger = User::factory()->create(['account_id' => $other->id, 'owner' => true]);
        $plain = $this->token(AgentAbilities::ALL, $stranger);

        $this->verb($plain, 'tasks', ['--json', '--status=all'])->assertOk()->assertDontSee('Footer');
        $this->verb($plain, 'clients', ['--json'])->assertOk()->assertDontSee('Bridge Co');
        $this->verb($plain, 'task-edit', [(string) $this->task->id, '--name=Hijacked', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '1')->assertSee('not_found');
        $this->verb($plain, 'plan', [(string) $this->task->id, '--json'])->assertOk()->assertHeader('X-Crm-Exit', '1')->assertSee('not_found');
        $this->verb($plain, 'task-create', ['--name=x', '--client='.$this->client->id, '--json'])->assertOk()->assertHeader('X-Crm-Exit', '1')->assertSee('not_found');
        $this->verb($plain, 'client-create', ['--name=Bridge Co', '--json'])->assertOk()->assertHeader('X-Crm-Exit', '0');

        $this->assertSame('Footer', $this->task->fresh()->name);
        $this->assertSame($other->id, Client::query()->where('account_id', '!=', $this->client->account_id)->sole()->account_id);
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function withTask(array $args): array
    {
        return array_map(fn (string $arg): string => $arg === 'TASK' ? (string) $this->task->id : $arg, $args);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities, ?User $user = null): string
    {
        $user ??= $this->owner;
        $issued = $user->createToken('agent', $abilities, now()->addDays(30));
        $issued->accessToken->forceFill(['account_id' => $user->account_id])->save();

        return $issued->plainTextToken;
    }

    /**
     * @param  list<string>  $args
     */
    private function verb(string $token, string $verb, array $args = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->post('/agent/verb', ['verb' => $verb, 'args' => $args]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function mcp(string $token, string $method, array $params = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }
}

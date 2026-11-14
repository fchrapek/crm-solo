<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\ClientRetainer;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Settlement\SettlementFixtures;
use Tests\TestCase;

/**
 * Agents change abonament positions through the same service as the abonament
 * dialog: only the fields given change, a month already owed or closed keeps
 * its terms, nothing is deleted, and it takes write:billing.
 */
final class AgentRetainerWritesTest extends TestCase
{
    use RefreshDatabase;
    use SettlementFixtures;

    private ClientRetainer $position;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSettlement(nowUtc: '2026-11-20 10:00:00');
        $this->position = $this->retainer(6, '2026-01-01');
        $this->position->update(['label' => 'Opieka', 'monthly_fee' => 1500]);
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::BILLING]);
    }

    public function test_a_fee_change_touches_only_the_fee_and_is_audited(): void
    {
        $this->verb('retainer-set', ['Acme', '--id='.$this->position->id, '--fee=1620', '--json'])
            ->assertHeader('X-Crm-Exit', '0')->assertJsonPath('retainer.monthly_fee', 1620)->assertJsonPath('message', 'Position updated.');

        $fresh = $this->position->fresh();
        $this->assertSame(1620.0, (float) $fresh->monthly_fee);
        $this->assertSame(6.0, (float) $fresh->monthly_hours);
        $this->assertSame('Opieka', $fresh->label);
        $this->assertSame('2026-01-01', $fresh->effective_from->toDateString());
        $this->assertSame('crm:retainer-set', AgentAuditEvent::query()->where('target_type', 'client_retainers')->sole()->verb);
    }

    public function test_an_hours_change_a_month_owed_depends_on_is_refused_like_in_the_dialog(): void
    {
        $this->verb('retainer-set', ['Acme', '--id='.$this->position->id, '--hours=8', '--json'])
            ->assertHeader('X-Crm-Exit', '1')->assertSee('refused')->assertSee('September 2026 is closed or being settled');

        $this->assertSame(6.0, (float) $this->position->fresh()->monthly_hours);
    }

    public function test_a_new_position_needs_hours_and_the_brief_lists_it_with_its_id(): void
    {
        $this->verb('retainer-set', ['Acme', '--fee=500', '--json'])->assertHeader('X-Crm-Exit', '1')->assertSee('invalid_argument');
        $this->assertSame(1, ClientRetainer::query()->count());

        $added = $this->verb('retainer-set', ['Acme', '--hours=0', '--fee=59', '--label=Subskrypcja', '--from=2026-12-01', '--group=2', '--json'])
            ->assertHeader('X-Crm-Exit', '0')->json('retainer');
        $this->assertSame(2, $added['invoice_group']);
        $this->assertSame('2026-12-01', $added['effective_from']);

        $brief = $this->verb('brief', ['Acme', '--json'])->json('retainers');
        $this->assertSame([$this->position->id], array_column($brief, 'id'));
        $this->assertSame('2026-01-01', $brief[0]['effective_from']);
    }

    public function test_none_clears_a_field(): void
    {
        $this->verb('retainer-set', ['Acme', '--id='.$this->position->id, '--label=none'])->assertHeader('X-Crm-Exit', '0');

        $this->assertNull($this->position->fresh()->label);
    }

    public function test_ending_defaults_to_next_month_and_an_elapsed_month_is_refused(): void
    {
        $this->verb('retainer-end', ['Acme', (string) $this->position->id, '--to=2026-10-01'])->assertHeader('X-Crm-Exit', '1')->assertSee('current month');
        $this->assertNull($this->position->fresh()->effective_to);

        $this->verb('retainer-end', ['Acme', (string) $this->position->id, '--json'])->assertHeader('X-Crm-Exit', '0')->assertJsonPath('retainer.effective_to', '2026-12-01');

        $this->assertSame(1, ClientRetainer::query()->count());
    }

    public function test_a_token_without_billing_is_refused_and_another_account_cannot_reach_the_position(): void
    {
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::CLIENTS]);
        $this->verb('retainer-set', ['Acme', '--id='.$this->position->id, '--fee=1'])->assertForbidden();

        $other = Account::create(['name' => 'Other']);
        $other->clients()->create(['name' => 'Acme', 'currency' => 'PLN']);
        $this->plain = $this->token([AgentAbilities::READ, AgentAbilities::BILLING], User::factory()->create(['account_id' => $other->id, 'owner' => true]));
        $this->verb('retainer-set', ['Acme', '--id='.$this->position->id, '--fee=1'])->assertHeader('X-Crm-Exit', '1')->assertSee('no retainer position');

        $this->assertSame(1500.0, (float) $this->position->fresh()->monthly_fee);
    }

    public function test_the_mcp_tools_write_the_same_way_and_say_to_confirm_first(): void
    {
        $tools = collect($this->mcp('tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        foreach (['retainer_set', 'retainer_end'] as $name) {
            $this->assertStringContainsString('wait for the owner', $tools[$name]['description']);
        }

        $this->mcp('tools/call', ['name' => 'retainer_set', 'arguments' => ['client' => 'Acme', 'id' => $this->position->id, 'monthly_fee' => 1620]])
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->mcp('tools/call', ['name' => 'retainer_set', 'arguments' => ['client' => 'Acme', 'id' => $this->position->id, 'monthly_hours' => 9]])
            ->assertOk()->assertJsonPath('result.isError', true);

        $fresh = $this->position->fresh();
        $this->assertSame(1620.0, (float) $fresh->monthly_fee);
        $this->assertSame(6.0, (float) $fresh->monthly_hours);
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

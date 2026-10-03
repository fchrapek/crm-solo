<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo crm prompt: a closed whitelist of verbs run in-process. The
 * whitelist IS the security boundary - these tests pin that nothing outside
 * it executes and that the endpoint does not exist off-demo.
 */
final class DemoCliTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Demo Studio']);
        $this->user = User::factory()->create([
            'account_id' => $account->id,
            'owner' => true,
        ]);
    }

    public function test_endpoint_is_absent_without_demo_mode(): void
    {
        config(['app.demo' => false]);

        $this->actingAs($this->user)
            ->postJson('/demo/cli', ['command' => 'crm today'])
            ->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        config(['app.demo' => true]);

        $this->postJson('/demo/cli', ['command' => 'crm today'])
            ->assertUnauthorized();
    }

    public function test_unknown_verbs_refuse_to_parse(): void
    {
        config(['app.demo' => true]);

        foreach (['migrate', 'tinker', 'db:seed', 'config:show'] as $forbidden) {
            $this->actingAs($this->user)
                ->postJson('/demo/cli', ['command' => $forbidden])
                ->assertOk()
                ->assertJson(fn ($json) => $json->where('output', fn ($output) => str_contains((string) $output, 'Unknown command')));
        }
    }

    public function test_help_lists_the_verbs(): void
    {
        config(['app.demo' => true]);

        $this->actingAs($this->user)
            ->postJson('/demo/cli', ['command' => 'help'])
            ->assertOk()
            ->assertJson(fn ($json) => $json->where('output', fn ($output) => str_contains((string) $output, 'crm today')));
    }

    public function test_whitelisted_verb_runs_against_the_database(): void
    {
        config(['app.demo' => true]);

        $response = $this->actingAs($this->user)
            ->postJson('/demo/cli', ['command' => 'crm today --json'])
            ->assertOk();

        $this->assertNotSame('', mb_trim((string) $response->json('output')));
    }

    public function test_shell_metacharacters_are_inert_arguments(): void
    {
        config(['app.demo' => true]);

        Client::create(['account_id' => $this->user->account_id, 'name' => 'Acme']);

        // Everything after the verb is tokenized by StringInput, never a
        // shell: `; rm -rf /` is just a client-name fragment that won't match.
        $response = $this->actingAs($this->user)
            ->postJson('/demo/cli', ['command' => 'crm brief "; rm -rf /"'])
            ->assertOk();

        $this->assertNotSame('', mb_trim((string) $response->json('output')));
        $this->assertDatabaseHas('clients', ['name' => 'Acme']);
    }

    public function test_each_visitor_ip_gets_its_own_throttle_bucket(): void
    {
        config(['app.demo' => true]);

        $run = fn (string $ip) => $this->actingAs($this->user)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/demo/cli', ['command' => 'crm today']);

        foreach (range(1, 30) as $_) {
            $run('198.51.100.1')->assertOk();
        }
        $run('198.51.100.1')->assertStatus(429);

        $run('198.51.100.2')->assertOk();
    }

    public function test_a_demo_prompt_write_is_audited_as_the_demo_prompt_not_the_cli(): void
    {
        config(['app.demo' => true]);
        Client::create(['account_id' => $this->user->account_id, 'name' => 'Acme']);

        $this->actingAs($this->user)
            ->postJson('/demo/cli', ['command' => 'crm note Acme "Called them"'])
            ->assertOk();

        $audit = AgentAuditEvent::query()->sole();
        $this->assertSame(['web-demo', 'crm:note', null], [$audit->via, $audit->verb, $audit->session_id]);
    }
}

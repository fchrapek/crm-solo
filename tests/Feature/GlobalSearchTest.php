<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\SearchTool;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\TestCase;

/**
 * ⌘K, `crm search` and the MCP search tool: one query over clients, tasks, contacts, leads, reports and
 * invoices inside the account, grouped in that order, archived records only when asked.
 */
final class GlobalSearchTest extends TestCase
{
    use CallsAgentSurfaces;
    use RefreshDatabase;

    private Account $account;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        config(['agent.local_account' => $this->account->id]);
        $this->user = User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Pracownia Mebli']);
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Strona']);

        Task::create(['project_id' => $project->id, 'name' => 'Formularz wyceny mebli', 'source' => 'manual', 'list_name' => 'To-Do']);
        Task::create(['project_id' => $project->id, 'name' => 'Galeria mebli', 'source' => 'manual', 'list_name' => 'Done', 'is_completed' => true, 'finished_at' => now()]);
        Contact::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'first_name' => 'Ewa', 'last_name' => 'Dąbek']);
        Lead::create(['account_id' => $this->account->id, 'pipeline' => Lead::pipelines()[0], 'name' => 'Meble na wymiar', 'source' => 'referral', 'email' => 'lead@example.com']);
        ClientReport::create([
            'account_id' => $this->account->id, 'client_id' => $this->client->id, 'period_type' => ClientReport::PERIOD_MONTH,
            'status' => ClientReport::STATUS_SENT, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'opening_balance_hours' => 0, 'actual_hours' => 2, 'composer_key' => 'manual',
        ]);
        Invoice::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'external_id' => 'i-1', 'number' => '21/09/2026', 'status' => 'paid', 'invoice_date' => '2026-09-21', 'net_price' => 1920, 'gross_price' => 2362]);

        $other = Account::factory()->create();
        Client::factory()->create(['account_id' => $other->id, 'name' => 'Meble Obce']);
    }

    public function test_one_query_finds_every_kind_of_record_grouped_in_the_palette_order_and_never_another_account(): void
    {
        $result = $this->actingAs($this->user)->getJson('/search?q=mebl')->assertOk()->json();

        $this->assertSame(['clients', 'tasks', 'contacts', 'leads', 'reports', 'invoices'], array_column($result['groups'], 'group'));
        $this->assertSame(['Pracownia Mebli'], array_column($result['groups'][0]['items'], 'title'));
        $this->assertSame("/clients/{$this->client->id}/przeglad", $result['groups'][0]['items'][0]['url']);
        $this->assertStringNotContainsString('Obce', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_done_tasks_and_archived_clients_stay_out_until_asked(): void
    {
        $this->client->delete();

        $plain = $this->actingAs($this->user)->getJson('/search?q=mebl')->json('groups');
        $this->assertNotContains('clients', array_column($plain, 'group'));
        $this->assertNotContains('Galeria mebli', array_column($plain[0]['items'] ?? [], 'title'));

        $all = collect($this->actingAs($this->user)->getJson('/search?q=mebl&archived=1')->json('groups'))->keyBy('group');
        $this->assertTrue($all['clients']['items'][0]['archived']);
        $this->assertContains('Galeria mebli', array_column($all['tasks']['items'], 'title'));
    }

    public function test_five_per_group_with_the_total_and_twenty_for_one_group(): void
    {
        $project = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Dużo']);
        foreach (range(1, 24) as $n) {
            Task::create(['project_id' => $project->id, 'name' => "Szafa {$n}", 'source' => 'manual', 'list_name' => 'To-Do']);
        }

        $tasks = $this->actingAs($this->user)->getJson('/search?q=szafa')->json('groups.0');
        $this->assertSame(24, $tasks['total']);
        $this->assertCount(5, $tasks['items']);
        $this->assertCount(20, $this->actingAs($this->user)->getJson('/search?q=szafa&group=tasks')->json('groups.0.items'));
    }

    public function test_an_invoice_shows_its_net_amount_in_zloty(): void
    {
        $invoices = collect($this->actingAs($this->user)->getJson('/search?q=21/09')->assertOk()->json('groups'))->firstWhere('group', 'invoices');

        $this->assertSame(19.2, $invoices['items'][0]['detail']['net']);
    }

    public function test_a_single_character_searches_nothing(): void
    {
        $this->assertSame([], $this->actingAs($this->user)->getJson('/search?q=m')->json('groups'));
    }

    public function test_the_verb_and_the_tool_answer_the_same_and_mark_form_text_as_untrusted(): void
    {
        [$exit, $verb] = $this->verb('crm:search', ['query' => 'meble']);
        [$ok, $tool] = $this->tool(SearchTool::class, ['query' => 'meble']);

        $this->assertSame(0, $exit);
        $this->assertTrue($ok);
        $this->assertSame($verb, $tool);
        $lead = collect($tool['groups'])->firstWhere('group', 'leads')['items'][0];
        $this->assertSame(['title', 'client'], $lead['untrusted']);
    }
}

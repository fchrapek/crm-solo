<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Tools\ProjectCreateTool;
use App\Models\Account;
use App\Models\AgentAuditEvent;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\CallsAgentSurfaces;
use Tests\TestCase;

/**
 * Agents add a project under a client through the project form's path. A name
 * the client already uses for a live project is refused until forced.
 */
final class AgentProjectCreateTest extends TestCase
{
    use CallsAgentSurfaces;
    use RefreshDatabase;

    private Account $account;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::factory()->create();
        User::factory()->create(['account_id' => $this->account->id, 'owner' => true]);
        config(['agent.local_account' => $this->account->id]);
        $this->client = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Pracownia Ceramiki Studio']);
    }

    public function test_project_create_adds_a_project_under_the_client_named_by_a_fragment(): void
    {
        [$exit, $out] = $this->verb('crm:project-create', ['--client' => 'pracownia ceramiki', '--name' => 'MAU', '--description' => 'Monthly upkeep']);

        $this->assertSame(0, $exit);
        $project = Project::query()->findOrFail($out['project']['id']);
        $this->assertSame([$this->account->id, $this->client->id, 'MAU', 'Monthly upkeep', null], [$project->account_id, $project->client_id, $project->name, $project->description, $project->trello_board_id]);
        $this->assertSame(['id' => $project->id, 'name' => 'MAU', 'description' => 'Monthly upkeep', 'client_id' => $this->client->id, 'client' => 'Pracownia Ceramiki Studio'], $out['project']);
        $this->assertFalse($out['forced']);
    }

    public function test_every_create_is_audited_with_the_verb_or_tool_that_made_it(): void
    {
        [, $verb] = $this->verb('crm:project-create', ['--client' => (string) $this->client->id, '--name' => 'Shop']);
        [, $tool] = $this->tool(ProjectCreateTool::class, ['client' => (string) $this->client->id, 'name' => 'Blog']);

        $audit = AgentAuditEvent::query()->where('target_type', 'projects')->where('action', 'created')->orderBy('id')->get();
        $this->assertSame(
            [[$verb['project']['id'], 'cli', 'crm:project-create'], [$tool['project']['id'], 'mcp', 'project_create']],
            $audit->map(fn (AgentAuditEvent $event): array => [(int) $event->target_id, $event->via, $event->verb])->all(),
        );
        $this->assertSame([$this->account->id], $audit->pluck('account_id')->unique()->values()->all());
    }

    public function test_a_name_the_client_already_uses_is_refused_with_the_match_until_forced(): void
    {
        $existing = Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Łódź Sklep']);

        [$exit, $out] = $this->verb('crm:project-create', ['--client' => (string) $this->client->id, '--name' => '  lodz   SKLEP ']);

        $this->assertSame([1, 'possible_duplicate'], [$exit, $out['error']]);
        $this->assertSame([['id' => $existing->id, 'name' => 'Łódź Sklep', 'context' => 'Pracownia Ceramiki Studio']], $out['candidates']);
        $this->assertSame(['candidates'], $out['untrusted']);
        $this->assertSame(['General', 'Łódź Sklep'], $this->client->projects()->orderBy('id')->pluck('name')->all());

        [$ok, $forced] = $this->tool(ProjectCreateTool::class, ['client' => (string) $this->client->id, 'name' => 'lodz sklep', 'force' => true]);

        $this->assertTrue($ok);
        $this->assertTrue($forced['forced']);
        $this->assertSame(['General', 'Łódź Sklep', 'lodz sklep'], $this->client->projects()->orderBy('id')->pluck('name')->all());
    }

    public function test_archived_projects_and_other_clients_projects_do_not_count_as_duplicates(): void
    {
        Project::create(['account_id' => $this->account->id, 'client_id' => $this->client->id, 'name' => 'Old Site', 'archived_at' => now()]);
        $other = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Another Client']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $other->id, 'name' => 'Landing']);

        [$archived] = $this->verb('crm:project-create', ['--client' => (string) $this->client->id, '--name' => 'Old Site']);
        [$elsewhere] = $this->verb('crm:project-create', ['--client' => (string) $this->client->id, '--name' => 'Landing']);

        $this->assertSame([0, 0], [$archived, $elsewhere]);
    }

    public function test_an_ambiguous_client_lists_the_candidates_and_creates_nothing(): void
    {
        $second = Client::factory()->create(['account_id' => $this->account->id, 'name' => 'Pracownia Ceramiki Shop']);
        $before = Project::query()->count();

        [$exit, $out] = $this->verb('crm:project-create', ['--client' => 'Pracownia Ceramiki', '--name' => 'MAU']);
        [$ok, $tool] = $this->tool(ProjectCreateTool::class, ['client' => 'pracownia ceramiki', 'name' => 'MAU']);

        $this->assertSame([1, 'ambiguous_reference'], [$exit, $out['error']]);
        $this->assertEqualsCanonicalizing([$this->client->id, $second->id], collect($out['candidates'])->pluck('id')->all());
        $this->assertFalse($ok);
        $this->assertSame('ambiguous_reference', $tool['error']);
        $this->assertSame($before, Project::query()->count());
    }

    public function test_another_accounts_client_is_not_found_and_gets_no_project(): void
    {
        $stranger = Account::factory()->create();
        $theirs = Client::factory()->create(['account_id' => $stranger->id, 'name' => 'Secret Co']);
        $before = $theirs->projects()->count();

        [$byId, $id] = $this->verb('crm:project-create', ['--client' => (string) $theirs->id, '--name' => 'Hijack']);
        [, $byName] = $this->tool(ProjectCreateTool::class, ['client' => 'Secret', 'name' => 'Hijack']);

        $this->assertSame([1, 'not_found'], [$byId, $id['error']]);
        $this->assertSame('not_found', $byName['error']);
        $this->assertSame($before, $theirs->projects()->count());
        $this->assertSame(0, Project::query()->where('name', 'Hijack')->count());
    }

    public function test_the_name_is_checked_with_the_project_forms_rules(): void
    {
        [$exit, $missing] = $this->verb('crm:project-create', ['--client' => (string) $this->client->id]);
        [, $long] = $this->tool(ProjectCreateTool::class, ['client' => (string) $this->client->id, 'name' => str_repeat('a', 256)]);
        [, $noClient] = $this->verb('crm:project-create', ['--name' => 'MAU']);

        $this->assertSame([1, 'invalid_argument'], [$exit, $missing['error']]);
        $this->assertArrayHasKey('name', $long['errors']);
        $this->assertSame('client', $noClient['field']);
        $this->assertSame(0, Project::query()->where('name', 'MAU')->count());
    }

    public function test_text_output_fences_the_project_and_the_duplicate_matches(): void
    {
        Artisan::call('crm:project-create', ['--client' => (string) $this->client->id, '--name' => 'MAU']);
        $created = Artisan::output();
        Artisan::call('crm:project-create', ['--client' => (string) $this->client->id, '--name' => 'mau']);
        $refused = Artisan::output();

        $this->assertStringStartsWith('Created project #', $created);
        $this->assertMatchesRegularExpression('/BEGIN EXTERNAL CONTENT [0-9a-f]{8}.*Pracownia Ceramiki Studio.*MAU.*END EXTERNAL CONTENT/s', $created);
        $this->assertStringStartsWith('ERROR ', $refused);
        $this->assertMatchesRegularExpression('/BEGIN EXTERNAL CONTENT [0-9a-f]{8}.*MAU.*END EXTERNAL CONTENT/s', $refused);
    }

    public function test_the_project_form_creates_through_the_same_creator(): void
    {
        $owner = User::query()->firstOrFail();

        $this->actingAs($owner)->post("/clients/{$this->client->id}/projects", ['name' => 'Form Project', 'description' => 'From the form'])->assertRedirect();
        $this->actingAs($owner)->post("/clients/{$this->client->id}/projects", ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');

        $project = Project::query()->where('name', 'Form Project')->sole();
        $this->assertSame([$this->account->id, $this->client->id, 'From the form'], [$project->account_id, $project->client_id, $project->description]);
    }
}

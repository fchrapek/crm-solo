<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A member works with clients, tasks, time and notes, and sets their own profile. Money, integrations and the account's
 * settings are the owner's: billing and the settings pages are absent for a member (404), the rest is forbidden (403).
 */
final class MemberAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Client $client;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();

        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $this->member = User::factory()->create(['account_id' => $account->id, 'owner' => false]);
        $this->client = Client::factory()->create(['account_id' => $account->id]);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $this->client->id, 'name' => 'Site']);
        $this->task = Task::create(['project_id' => $this->project->id, 'name' => 'Header', 'source' => 'manual']);
        Integration::create(['account_id' => $account->id, 'provider' => 'trello', 'is_enabled' => true, 'api_key' => 'stored-token', 'settings' => []]);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, int}>
     */
    public static function ownerOnly(): array
    {
        return [
            'replace integration credentials' => ['put', '/integrations/trello', ['is_enabled' => true, 'api_key' => 'mine'], 403],
            'run an integration sync' => ['post', '/integrations/trello/sync', [], 403],
            'disconnect an integration' => ['delete', '/integrations/trello', [], 403],
            'connect a project to a board' => ['post', '/projects/{project}/connect-trello', ['trello_board_id' => 'b'], 403],
            'switch sms sending' => ['patch', '/settings/sms/sending', ['provider' => 'smsapi'], 403],
            'write an sms template' => ['post', '/settings/sms/templates', ['key' => 'k', 'name' => 'N', 'body' => 'B'], 403],
            'write an sms rule' => ['post', '/settings/sms/rules', ['key' => 'k'], 403],
            'change lead settings' => ['patch', '/settings/leadgen', [], 403],
            'define a client field' => ['put', '/settings/client-fields/visit_at', ['label' => 'Visit'], 403],
            'delete a client' => ['delete', '/clients/{client}', [], 403],
            'open the integrations settings' => ['get', '/settings/integrations', [], 404],
            'open the settings' => ['get', '/settings', [], 404],
            'open the general settings' => ['get', '/settings/general', [], 404],
            'open the leads settings' => ['get', '/settings/leads', [], 404],
            'open the forms settings' => ['get', '/settings/forms', [], 404],
            'open the messages settings' => ['get', '/settings/messages', [], 404],
            'open the billing tab' => ['get', '/clients/{client}/billing', [], 404],
            'open the month close' => ['get', '/month-close', [], 404],
            'open revenue' => ['get', '/revenue', [], 404],
            'generate a report' => ['post', '/clients/{client}/reports', ['period' => '2026-10'], 404],
            'add a retainer' => ['post', '/clients/{client}/retainers', ['monthly_hours' => 5], 404],
            'set billed time' => ['put', '/clients/{client}/billed/2026-10/tasks/{task}', ['hours' => 2], 404],
            'close a month' => ['post', '/clients/{client}/months/2026-10/close', ['hash' => 'x'], 404],
            'reopen a month' => ['delete', '/clients/{client}/months/2026-10/close', [], 404],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('ownerOnly')]
    public function test_a_member_does_not_reach_what_is_the_owners(string $method, string $uri, array $payload, int $status): void
    {
        $uri = str_replace(['{client}', '{project}', '{task}'], [(string) $this->client->id, (string) $this->project->id, (string) $this->task->id], $uri);

        $this->actingAs($this->member)->json($method, $uri, $payload)->assertStatus($status);

        $this->assertSame('stored-token', Integration::query()->sole()->api_key);
        $this->assertNotNull($this->client->fresh());
    }

    public function test_a_member_works_with_clients_tasks_and_time(): void
    {
        $this->actingAs($this->member)->get("/clients/{$this->client->id}/overview")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.account.features.billing', false)->where('client.billing', false));
        $this->actingAs($this->member)->get('/profile')->assertOk();

        $this->actingAs($this->member)->post("/projects/{$this->project->id}/tasks", ['name' => 'Footer'])->assertSessionHasNoErrors();
        $this->assertSame(2, Task::query()->count());

        $this->actingAs($this->member)->postJson("/tasks/{$this->task->id}/time-entries/start")->assertCreated();
        $this->assertSame(1, TimeEntry::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The project page: the Zadania lanes narrowed to one project, inside the owner's account. */
final class ProjectPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_lists_only_this_projects_open_tasks_in_the_zadania_lanes(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::factory()->create(['account_id' => $account->id, 'name' => 'Pracownia Mebli Sp. z o.o.']);
        $site = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Galeria']);
        $other = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Sklep']);
        Task::create(['project_id' => $site->id, 'name' => 'Lightbox', 'source' => 'manual', 'list_name' => 'Doing']);
        Task::create(['project_id' => $other->id, 'name' => 'Koszyk', 'source' => 'manual', 'list_name' => 'Doing']);

        $this->actingAs($user)
            ->get("/clients/{$client->id}/projects/{$site->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('project-board/show')
                ->where('project.name', 'Galeria')
                ->where('client.short_name', 'Pracownia Mebli')
                ->where('tasks.lanes.0.key', 'doing')
                ->where('tasks.lanes.0.count', 1)
                ->where('tasks.lanes.0.rows.0.name', 'Lightbox')
                ->where('tasks.projects', [['id' => $site->id, 'name' => 'Galeria']]));
    }

    public function test_another_accounts_project_answers_not_found(): void
    {
        $user = User::factory()->create(['account_id' => Account::factory()->create()->id, 'owner' => true]);
        $stranger = Account::factory()->create();
        $client = Client::factory()->create(['account_id' => $stranger->id]);
        $project = Project::create(['account_id' => $stranger->id, 'client_id' => $client->id, 'name' => 'Obcy']);

        $this->actingAs($user)->get("/clients/{$client->id}/projects/{$project->id}")->assertNotFound();
    }

    public function test_the_months_hours_stop_at_local_midnight_so_an_entry_starting_on_the_first_belongs_to_the_next_month(): void
    {
        config(['app.display_timezone' => 'Europe/Warsaw']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 12:00:00', 'Europe/Warsaw')->utc());
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::factory()->create(['account_id' => $account->id]);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Galeria']);
        $entry = fn (string $start, int $minutes) => TimeEntry::create([
            'account_id' => $account->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => \Illuminate\Support\Carbon::parse($start, 'Europe/Warsaw')->utc(),
            'end_time' => \Illuminate\Support\Carbon::parse($start, 'Europe/Warsaw')->utc()->addMinutes($minutes),
            'duration_minutes' => $minutes,
            'billable' => true,
        ]);
        $entry('2026-10-31 23:00:00', 30);
        $entry('2026-11-01 00:00:00', 60);

        $this->actingAs($user)
            ->get("/clients/{$client->id}/projects/{$project->id}")
            ->assertInertia(fn (Assert $page) => $page->where('project.month', '2026-10')->where('project.month_minutes', 30));
    }

    public function test_an_agent_timer_on_the_project_shows_as_the_live_agent_and_the_owners_own_does_not(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 10:00:00'));
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = Client::factory()->create(['account_id' => $account->id]);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Galeria']);
        $token = $user->createToken('codex macbook', ['read', 'write'])->accessToken;
        $token->forceFill(['account_id' => $account->id])->save();
        $running = fn (?int $tokenId, int $minutesAgo) => TimeEntry::create([
            'account_id' => $account->id,
            'project_id' => $project->id,
            'client_id' => $client->id,
            'source' => TimeEntry::SOURCE_MANUAL,
            'start_time' => now()->subMinutes($minutesAgo),
            'billable' => true,
        ])->forceFill(['actor_token_id' => $tokenId, 'actor_via' => $tokenId !== null ? 'mcp' : null])->save();
        $page = fn () => $this->actingAs($user)->get("/clients/{$client->id}/projects/{$project->id}");

        $running(null, 30);
        $page()->assertInertia(fn (Assert $page) => $page->where('project.live_agent', null));

        $running($token->id, 14);
        $page()->assertInertia(fn (Assert $page) => $page
            ->where('project.live_agent.agent', 'Codex')
            ->where('project.live_agent.started_at', now()->subMinutes(14)->toIso8601String()));
    }
}

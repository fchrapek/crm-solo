<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Agent\TimeLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientGeneralProjectTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Studio']);
    }

    public function test_any_new_client_gets_one_private_general_project(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'Direct']);

        $project = $client->projects()->sole();
        $this->assertSame('General', $project->name);
        $this->assertNull($project->trello_board_id);
        $this->assertSame($this->account->id, $project->account_id);
    }

    public function test_ensuring_the_general_project_twice_creates_it_once(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'Direct']);

        $this->assertSame($client->ensureGeneralProject()->id, $client->ensureGeneralProject()->id);
        $this->assertSame(1, $client->projects()->count());
    }

    public function test_the_backfill_is_a_dry_run_by_default(): void
    {
        $client = $this->clientWithoutProjects('Bare');

        $this->artisan('clients:ensure-general-project')
            ->expectsOutputToContain("#{$client->id} Bare")
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, $client->projects()->count());
    }

    public function test_the_backfill_creates_missing_general_projects_once(): void
    {
        $bare = $this->clientWithoutProjects('Bare');
        $trelloOnly = $this->clientWithoutProjects('Trello only');
        Project::create(['account_id' => $this->account->id, 'client_id' => $trelloOnly->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $covered = Client::create(['account_id' => $this->account->id, 'name' => 'Covered']);
        $deleted = $this->clientWithoutProjects('Deleted');
        $deleted->delete();

        $this->artisan('clients:ensure-general-project --apply')->assertSuccessful();
        $this->artisan('clients:ensure-general-project --apply')
            ->expectsOutputToContain('Every client has a General project.')
            ->assertSuccessful();

        $this->assertSame(['General'], $bare->projects()->pluck('name')->all());
        $this->assertSame(['Board', 'General'], $trelloOnly->projects()->orderBy('id')->pluck('name')->all());
        $this->assertSame(1, $covered->projects()->count());
        $this->assertSame(0, $deleted->projects()->count(), 'a deleted client is left alone');
    }

    public function test_the_backfill_can_be_limited_to_one_account(): void
    {
        $other = Account::create(['name' => 'Other']);
        $mine = $this->clientWithoutProjects('Mine');
        $theirs = Client::create(['account_id' => $other->id, 'name' => 'Theirs']);
        Project::where('client_id', $theirs->id)->delete();

        $this->artisan("clients:ensure-general-project --apply --account={$this->account->id}")->assertSuccessful();

        $this->assertSame(1, $mine->projects()->count());
        $this->assertSame(0, $theirs->projects()->count());
    }

    public function test_the_general_project_is_the_one_still_named_general_else_the_oldest_private_one(): void
    {
        $client = $this->clientWithoutProjects('Direct');
        Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $site = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Site']);
        Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Shop']);

        $this->assertSame($site->id, $client->generalProject()?->id);

        $general = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'General']);

        $this->assertSame($general->id, $client->generalProject()?->id);
        $this->assertSame($general->id, $client->ensureGeneralProject()->id);
    }

    public function test_reading_the_general_project_never_creates_one(): void
    {
        $client = $this->clientWithoutProjects('Bare');

        $this->assertNull($client->generalProject());
        $this->assertSame(0, $client->projects()->count());
    }

    public function test_client_level_time_lands_on_the_renamed_catch_all_project(): void
    {
        $client = $this->clientWithoutProjects('Direct');
        Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Board', 'trello_board_id' => 'b1']);
        $catchAll = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Audyt energetyczny']);

        app(TimeLogger::class)->log(accountId: $this->account->id, minutes: 30, client: (string) $client->id);

        $this->assertSame($catchAll->id, TimeEntry::sole()->project_id);
    }

    public function test_wiping_a_clients_projects_keeps_the_renamed_catch_all_project(): void
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => 'Direct']);
        $catchAll = $client->generalProject();
        $catchAll->update(['name' => 'Audyt energetyczny']);
        $site = Project::create(['account_id' => $this->account->id, 'client_id' => $client->id, 'name' => 'Site']);

        $this->artisan('projects:delete', ['--client' => (string) $client->id, '--force' => true])->assertSuccessful();

        $this->assertNotNull(Project::find($catchAll->id));
        $this->assertNull(Project::find($site->id));

        $this->artisan('projects:delete', ['--client' => (string) $client->id, '--include-general' => true, '--force' => true])->assertSuccessful();

        $this->assertNull(Project::find($catchAll->id));
    }

    private function clientWithoutProjects(string $name): Client
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => $name]);
        Project::where('client_id', $client->id)->delete();

        return $client;
    }
}

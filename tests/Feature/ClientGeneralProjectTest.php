<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
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

    private function clientWithoutProjects(string $name): Client
    {
        $client = Client::create(['account_id' => $this->account->id, 'name' => $name]);
        Project::where('client_id', $client->id)->delete();

        return $client;
    }
}

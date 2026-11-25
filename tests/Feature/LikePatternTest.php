<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LikePatternTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acc']);
        $this->user = User::factory()->create(['account_id' => $account->id, 'first_name' => 'F', 'last_name' => 'C', 'email' => 'u@example.com', 'owner' => true]);
        $client = Client::create(['account_id' => $account->id, 'name' => 'Rabat 50%', 'type' => 'business']);
        Client::create(['account_id' => $account->id, 'name' => 'Rabat 500', 'type' => 'business']);
        $project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
        foreach (['Cena 50% off', 'Cena 500 off', 'plik a_b', 'plik axb', 'C:\\dane', 'C:dane', 'Hej! tu', 'Hej tu'] as $name) {
            Task::create(['project_id' => $project->id, 'name' => $name]);
        }
    }

    public function test_percent_underscore_backslash_and_the_escape_character_are_taken_literally_in_the_add_field(): void
    {
        $this->assertSame(['Cena 50% off'], $this->addFieldMatches('50%'));
        $this->assertSame(['plik a_b'], $this->addFieldMatches('a_b'));
        $this->assertSame(['C:\\dane'], $this->addFieldMatches(':\\d'));
        $this->assertSame(['Hej! tu'], $this->addFieldMatches('j!'));
    }

    public function test_percent_underscore_and_backslash_are_taken_literally_in_the_search_for_tasks_and_clients(): void
    {
        $this->assertSame(['Cena 50% off'], $this->searchTitles('50%', 'tasks'));
        $this->assertSame(['plik a_b'], $this->searchTitles('a_b', 'tasks'));
        $this->assertSame(['C:\\dane'], $this->searchTitles(':\\d', 'tasks'));
        $this->assertSame(['Rabat 50%'], $this->searchTitles('50%', 'clients'));
    }

    /** @return list<string> */
    private function addFieldMatches(string $text): array
    {
        return $this->actingAs($this->user)->getJson('/day/add/tasks?'.http_build_query(['q' => $text]))->assertOk()->json('tasks.*.name');
    }

    /** @return list<string> */
    private function searchTitles(string $text, string $group): array
    {
        return $this->actingAs($this->user)->getJson('/search?'.http_build_query(['q' => $text, 'group' => $group]))->assertOk()->json('groups.0.items.*.title') ?? [];
    }
}

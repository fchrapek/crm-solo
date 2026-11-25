<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A repository's remote is shown as a link, so it is a web address or a git remote and nothing else. */
final class RepositoryRemoteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::create(['name' => 'Acme']);
        $this->owner = User::factory()->create(['account_id' => $account->id, 'owner' => true]);
        $client = $account->clients()->create(['name' => 'Acme']);
        $this->project = Project::create(['account_id' => $account->id, 'client_id' => $client->id, 'name' => 'Site']);
    }

    /** @return array<string, array{string}> */
    public static function remotes(): array
    {
        return [
            'https' => ['https://github.com/example/site'],
            'ssh url' => ['ssh://git@example.test/site.git'],
            'scp form' => ['git@git.example.test:example/site.git'],
            'scp form, another user' => ['deploy@example.test:team/site.git'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function notRemotes(): array
    {
        return [
            'script' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<p>hi</p>'],
            'a file' => ['file:///etc/passwd'],
            'a mail address' => ['mailto:someone@example.test'],
            'a remote with a space' => ['https://example.test/a b'],
        ];
    }

    #[DataProvider('remotes')]
    public function test_a_web_address_or_a_git_remote_is_stored(string $remote): void
    {
        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/repositories", ['name' => 'site', 'remote_url' => $remote])
            ->assertSessionHasNoErrors();

        $this->assertSame($remote, Repository::query()->sole()->remote_url);
    }

    #[DataProvider('notRemotes')]
    public function test_anything_else_is_refused_on_create_and_on_edit(string $remote): void
    {
        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/repositories", ['name' => 'site', 'remote_url' => $remote])
            ->assertSessionHasErrors('remote_url');
        $this->assertSame(0, Repository::query()->count());

        $repository = Repository::create(['project_id' => $this->project->id, 'name' => 'site', 'provider' => 'local']);
        $this->actingAs($this->owner)->put("/repositories/{$repository->id}", ['name' => 'site', 'remote_url' => $remote])
            ->assertSessionHasErrors('remote_url');
        $this->assertNull($repository->fresh()->remote_url);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Account;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every command declares how it treats accounts, and every command that acts
 * for the acting identity finds nothing and changes nothing in another
 * account. A new command without a declaration fails here.
 */
final class CommandAccountScopeTest extends TestCase
{
    use RefreshDatabase;

    private Account $mine;

    private Client $otherClient;

    private Project $otherProject;

    private Task $otherTask;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->mine = Account::factory()->create();
        User::factory()->create(['account_id' => $this->mine->id, 'owner' => true]);
        Client::factory()->create(['account_id' => $this->mine->id, 'name' => 'Mine Co']);

        $other = Account::factory()->create();
        $this->otherClient = Client::factory()->create([
            'account_id' => $other->id, 'name' => 'Other Co', 'month_close_type' => 'maintenance',
            'include_in_month_close' => true, 'external_ids' => ['infakt' => 77],
        ]);
        $this->otherProject = Project::create(['account_id' => $other->id, 'client_id' => $this->otherClient->id, 'name' => 'Other site']);
        Repository::create(['project_id' => $this->otherProject->id, 'name' => 'r', 'local_path' => '/tmp/other-site', 'provider' => 'local']);
        $this->otherTask = Task::create(['project_id' => $this->otherProject->id, 'name' => 'Other task', 'source' => 'manual']);
        Integration::create(['account_id' => $other->id, 'provider' => 'infakt', 'is_enabled' => true, 'api_key' => 'k']);
        Integration::create(['account_id' => $other->id, 'provider' => 'trello', 'is_enabled' => true, 'api_key' => 't', 'settings' => ['trello_api_key' => 'k']]);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function commands(): array
    {
        return [
            'projects:archive' => ['projects:archive', ['project' => ['__PROJECT__']]],
            'projects:archive by name' => ['projects:archive', ['project' => ['Other site']]],
            'projects:create' => ['projects:create', ['--client' => '__CLIENT__', '--name' => ['Sneaky']]],
            'projects:delete' => ['projects:delete', ['project' => '__PROJECT__', '--force' => true]],
            'tasks:create' => ['tasks:create', ['--project' => '__PROJECT__', '--name' => ['Sneaky']]],
            'tasks:delete' => ['tasks:delete', ['--project' => '__PROJECT__', '--force' => true]],
            'tasks:cli' => ['tasks:cli', ['task' => '__TASK__', 'cli' => 'claude']],
            'reports:generate' => ['reports:generate', ['client' => '__CLIENT__', '--composer' => 'structured_list']],
            'infakt:invoices' => ['infakt:invoices', ['client' => '__CLIENT__']],
            'infakt:draft-invoice' => ['infakt:draft-invoice', ['client' => '__CLIENT__', '--dry-run' => true]],
            'infakt:client with another account' => ['infakt:client', ['ref' => '77', '--account' => '__ACCOUNT__']],
            'trello:adopt with another account' => ['trello:adopt', ['--account' => '__ACCOUNT__']],
            'reports:prompt-show with another account' => ['reports:prompt-show', ['--account' => '__ACCOUNT__']],
            'reports:prompt-import with another account' => ['reports:prompt-import', ['path' => '/dev/null', '--account' => '__ACCOUNT__']],
            'projects:create with another account' => ['projects:create', ['--client' => 'Mine', '--name' => ['X'], '--account' => '__ACCOUNT__']],
            'time:log with another account' => ['time:log', ['minutes' => 5, '--client' => 'Mine', '--account' => '__ACCOUNT__']],
        ];
    }

    public function test_every_command_declares_its_account_scope(): void
    {
        foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $file) {
            $class = 'App\\Console\\Commands\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(AccountScope::class);

            $this->assertCount(1, $attributes, "{$class} declares no AccountScope: classify it as acting, operator or none.");
            $scope = $attributes[0]->newInstance()->scope;
            $this->assertContains($scope, [AccountScope::ACTING, AccountScope::OPERATOR, AccountScope::NONE], $class);

            if ($scope === AccountScope::ACTING) {
                $this->assertContains(AgentConsoleOutput::class, $this->traitsOf($reflection), "{$class} acts for the acting account but cannot resolve it.");
            }
            if ($scope === AccountScope::OPERATOR) {
                /** @var Command $command */
                $command = app($class);
                $this->assertStringContainsString('operator', $command->getDescription(), "{$class} works across accounts and must say so.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('commands')]
    public function test_another_accounts_records_are_not_found_and_not_changed(string $command, array $arguments): void
    {
        $swap = fn (mixed $v): mixed => match ($v) {
            '__PROJECT__' => (string) $this->otherProject->id,
            '__CLIENT__' => (string) $this->otherClient->id,
            '__TASK__' => (string) $this->otherTask->id,
            '__ACCOUNT__' => (string) $this->otherClient->account_id,
            default => is_array($v) ? array_map(fn ($x) => $x === '__PROJECT__' ? (string) $this->otherProject->id : $x, $v) : $v,
        };
        $arguments = array_map($swap, $arguments);
        $before = $this->snapshot();

        $exit = Artisan::call($command, $arguments);

        $this->assertNotSame(0, $exit, "{$command} succeeded against another account");
        $this->assertSame($before, $this->snapshot(), "{$command} changed another account's records");
        $this->assertStringNotContainsString('Other Co', Artisan::output());
        $this->assertStringNotContainsString('Other site', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_month_close_maps_never_touch_another_account(): void
    {
        $this->otherProject->update(['include_in_month_close' => false]);

        Artisan::call('month-close:sync-sites', ['--apply' => true]);
        $this->assertStringNotContainsString('Other', Artisan::output());
        $this->assertFalse($this->otherProject->fresh()->include_in_month_close);

        Artisan::call('month-close:map-backups', ['--apply' => true]);
        $this->assertStringNotContainsString('Other', Artisan::output());
    }

    public function test_dummy_lead_removal_stays_in_the_acting_account(): void
    {
        $theirs = Lead::create(['account_id' => $this->otherClient->account_id, 'pipeline' => Lead::pipelines()[0], 'name' => 'Theirs', 'source' => Lead::sources()[0], 'external_ref' => 'dummy-look-test-99']);

        Artisan::call('leads:seed-dummy', ['--remove' => true]);

        $this->assertNotNull(Lead::find($theirs->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'projects' => Project::query()->where('account_id', $this->otherClient->account_id)->orderBy('id')->get(['id', 'name', 'archived_at'])->toArray(),
            'tasks' => Task::query()->where('project_id', $this->otherProject->id)->orderBy('id')->get(['id', 'name', 'cli', 'archived_at'])->toArray(),
            'reports' => $this->otherClient->reports()->count(),
            'all_projects' => Project::count(),
            'all_tasks' => Task::count(),
        ];
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private function traitsOf(ReflectionClass $class): array
    {
        $traits = [];
        $queue = $class->getTraitNames();
        while ($queue !== []) {
            $trait = array_shift($queue);
            $traits[] = $trait;
            $queue = [...$queue, ...(new ReflectionClass($trait))->getTraitNames()];
        }

        return $traits;
    }
}

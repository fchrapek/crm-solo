<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Concerns\SpawnEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A git hook exports GIT_DIR and its kin. Neither the test suite nor the
 * app's own git calls may act on that repository instead of their target.
 */
final class GitHookEnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/git-hook-env-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->root.'/outer', recursive: true);
        File::makeDirectory($this->root.'/inner', recursive: true);
        (new Process(['git', 'init', '-q'], $this->root.'/outer', SpawnEnvironment::withoutGitRepository()))->mustRun();
    }

    protected function tearDown(): void
    {
        $this->forgetInheritedGitDir();
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_the_variable_list_matches_the_installed_git(): void
    {
        $listed = (new Process(['git', 'rev-parse', '--local-env-vars']))->mustRun()->getOutput();

        $this->assertEqualsCanonicalizing(
            preg_split('/\R/', mb_trim($listed)),
            SpawnEnvironment::GIT_REPOSITORY_ENV,
        );
    }

    public function test_the_test_bootstrap_clears_an_inherited_git_dir(): void
    {
        $probe = new Process(
            ['php', '-d', 'variables_order=EGPCS', '-r', 'require "tests/bootstrap.php"; echo json_encode([getenv("GIT_DIR"), $_ENV["GIT_DIR"] ?? null, $_SERVER["GIT_DIR"] ?? null]);'],
            base_path(),
            ['GIT_DIR' => $this->root.'/outer/.git'],
        );

        $this->assertSame('[false,null,null]', mb_trim($probe->mustRun()->getOutput()));
    }

    public function test_a_symfony_git_process_ignores_an_inherited_git_dir(): void
    {
        $this->inheritGitDir();

        (new Process(['git', 'init', '-q'], $this->root.'/inner', SpawnEnvironment::withoutGitRepository()))->mustRun();
        (new Process(['git', '-C', $this->root.'/inner', 'config', 'user.name', 'Probe'], null, SpawnEnvironment::withoutGitRepository()))->mustRun();

        $this->assertInnerWrittenOuterUntouched();
    }

    public function test_a_laravel_git_process_ignores_an_inherited_git_dir(): void
    {
        $this->inheritGitDir();

        ProcessFacade::path($this->root.'/inner')->env(SpawnEnvironment::withoutGitRepository())->run(['git', 'init', '-q'])->throw();
        ProcessFacade::path($this->root.'/inner')->env(SpawnEnvironment::withoutGitRepository())->run(['git', 'config', 'user.name', 'Probe'])->throw();

        $this->assertInnerWrittenOuterUntouched();
    }

    public function test_phpunit_loads_the_bootstrap_that_clears_the_variables(): void
    {
        $config = simplexml_load_file(base_path('phpunit.xml'));

        $this->assertSame('tests/bootstrap.php', (string) $config['bootstrap']);
    }

    public function test_every_pre_push_job_strips_the_variables(): void
    {
        $hooks = Yaml::parseFile(base_path('lefthook.yml'));

        foreach ($hooks['pre-push']['jobs'] as $job) {
            $this->assertStringStartsWith("env $(git rev-parse --local-env-vars | sed 's/^/-u /') ", $job['run'], $job['name']);
        }
    }

    public function test_every_git_call_in_the_app_drops_the_variables(): void
    {
        $unguarded = [];
        foreach (File::allFiles(app_path()) as $file) {
            $lines = file($file->getPathname());
            foreach ($lines as $i => $line) {
                if (! preg_match("/\[\s*'git'|^\s*'git',/", $line)) {
                    continue;
                }
                // The statement around the call: a few lines up to the
                // Process or run( that opens it, a few down to its close.
                $context = implode('', array_slice($lines, max(0, $i - 3), 12));
                if (! str_contains($context, 'withoutGitRepository()') && ! str_contains($context, '$this->run(')) {
                    $unguarded[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $unguarded, 'git spawned without SpawnEnvironment::withoutGitRepository()');
        $this->assertStringContainsString(
            'new Process($command, null, SpawnEnvironment::withoutGitRepository())',
            File::get(app_path('Services/Concerns/ManagesTtydProcess.php')),
            'ManagesTtydProcess::run(), which the launcher uses for git, must drop the variables',
        );
    }

    private function inheritGitDir(): void
    {
        foreach (['GIT_DIR' => $this->root.'/outer/.git', 'GIT_WORK_TREE' => $this->root.'/outer'] as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    private function forgetInheritedGitDir(): void
    {
        foreach (['GIT_DIR', 'GIT_WORK_TREE'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    private function assertInnerWrittenOuterUntouched(): void
    {
        $this->forgetInheritedGitDir();
        $inner = new Process(['git', '-C', $this->root.'/inner', 'config', '--local', '--get', 'user.name']);
        $inner->run();
        $outer = new Process(['git', '-C', $this->root.'/outer', 'config', '--local', '--get', 'user.name']);
        $outer->run();

        $this->assertSame('Probe', mb_trim($inner->getOutput()), 'the inner repository was not written');
        $this->assertSame('', mb_trim($outer->getOutput()), 'the outer repository was written to');
    }
}

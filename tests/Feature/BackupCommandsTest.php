<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

final class BackupCommandsTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = sys_get_temp_dir().'/crm-backup-test-'.bin2hex(random_bytes(6));
        mkdir($this->backupDir, 0755, true);
        config(['backup.path' => $this->backupDir]);

        // Any process without a matching fake throws, so no real mariadb, dump, docker or gunzip can run.
        Process::fake([]);
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);

        parent::tearDown();
    }

    public function test_backup_writes_a_gzipped_dump_named_by_timestamp(): void
    {
        $this->travelTo('2026-10-01 12:34:56');
        $this->fakeDump();

        $this->artisan('db:backup')
            ->assertSuccessful()
            ->expectsOutputToContain('Backup created: backup-2026-10-01-123456.sql.gz');

        $this->assertSame(['backup-2026-10-01-123456.sql.gz'], $this->backupNames());
        $file = "{$this->backupDir}/backup-2026-10-01-123456.sql.gz";
        $this->assertGreaterThan(100, filesize($file));
        $this->assertStringContainsString('-- Dump completed', (string) gzdecode((string) file_get_contents($file)));
    }

    public function test_backup_retention_keeps_only_the_newest_daily_backups(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(10);
        $this->fakeDump();

        $this->artisan('db:backup', ['--retention' => 3, '--weekly-retention' => 0])
            ->assertSuccessful()
            ->expectsOutputToContain('Cleaned 8 old backup(s).');

        $this->assertSame([
            'backup-2026-10-01-120000.sql.gz',
            'backup-2026-09-30-120000.sql.gz',
            'backup-2026-09-29-120000.sql.gz',
        ], $this->backupNames());
    }

    public function test_backup_retention_keeps_one_backup_per_week_beyond_the_daily_window(): void
    {
        // 2026-10-01 is a Thursday; weeks start on Sunday (09-27, 09-20).
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(20);
        $this->fakeDump();

        $this->artisan('db:backup', ['--retention' => 2, '--weekly-retention' => 2])
            ->assertSuccessful();

        $this->assertSame([
            'backup-2026-10-01-120000.sql.gz',
            'backup-2026-09-30-120000.sql.gz',
            'backup-2026-09-29-120000.sql.gz',
            'backup-2026-09-26-120000.sql.gz',
        ], $this->backupNames());
    }

    public function test_backup_reports_a_failed_dump_and_keeps_older_backups(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(10);
        Process::fake([
            '*gzip >*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
            '*which*' => Process::result(),
        ]);

        $this->artisan('db:backup', ['--retention' => 3, '--weekly-retention' => 0])
            ->assertFailed()
            ->expectsOutputToContain('Backup failed');

        $this->assertCount(10, $this->backupNames());
        $this->assertNotContains('backup-2026-10-01-120000.sql.gz', $this->backupNames());
        $this->assertNoTemporaryFiles();
    }

    public function test_backup_rejects_a_dump_cut_off_before_its_completion_trailer(): void
    {
        // The dump side died but gzip exited cleanly, so the pipe looked successful.
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(10);
        $this->fakeDump("-- MariaDB dump\n".str_repeat("INSERT INTO t VALUES ('".bin2hex(random_bytes(64))."');\n", 8));

        $this->artisan('db:backup', ['--retention' => 3, '--weekly-retention' => 0])
            ->assertFailed()
            ->expectsOutputToContain('Dump is incomplete');

        $this->assertCount(10, $this->backupNames());
        $this->assertNotContains('backup-2026-10-01-120000.sql.gz', $this->backupNames());
        $this->assertNoTemporaryFiles();
    }

    public function test_backup_rejects_a_complete_dump_under_the_size_floor(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(5);
        $sql = "-- Dump completed\n";
        $this->assertLessThan(100, mb_strlen((string) gzencode($sql), '8bit'));
        $this->fakeDump($sql);

        $this->artisan('db:backup', ['--retention' => 1, '--weekly-retention' => 0])
            ->assertFailed()
            ->expectsOutputToContain('Backup failed: Empty dump file');

        $this->assertCount(5, $this->backupNames());
        $this->assertNotContains('backup-2026-10-01-120000.sql.gz', $this->backupNames());
        $this->assertNoTemporaryFiles();
    }

    public function test_backup_leaves_no_file_when_the_pipe_fails_after_writing(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(5);
        $this->fakeDump(exitCode: 1);

        $this->artisan('db:backup', ['--retention' => 1, '--weekly-retention' => 0])
            ->assertFailed()
            ->expectsOutputToContain('Backup failed');

        $this->assertCount(5, $this->backupNames());
        $this->assertSame(5, count(glob("{$this->backupDir}/*") ?: []));
        $this->assertNoTemporaryFiles();
    }

    public function test_backup_runs_under_pipefail_with_the_password_in_the_environment(): void
    {
        config(['database.connections.mariadb.password' => 's3cret-pw']);
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_contains($this->script($process), 'gzip >')
            && str_contains($this->script($process), 'set -o pipefail;')
            && ! str_contains($this->script($process), 's3cret-pw')
            && ($process->environment['MYSQL_PWD'] ?? null) === 's3cret-pw');
    }

    public function test_backup_through_docker_passes_the_password_through_the_environment(): void
    {
        config(['database.connections.mariadb.password' => 's3cret-pw']);
        $this->fakeDump();
        Process::fake([
            '*which*' => Process::result(exitCode: 1),
            '*docker compose ps*' => Process::result(output: "abc123\n"),
        ]);

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_contains($this->script($process), 'set -o pipefail; docker compose exec -T -e MYSQL_PWD mariadb mariadb-dump')
            && ! str_contains($this->script($process), 's3cret-pw')
            && ($process->environment['MYSQL_PWD'] ?? null) === 's3cret-pw');
    }

    public function test_backup_writes_into_an_exclusively_created_temporary_file(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $targets = [];
        Process::fake([
            '*gzip >*' => function (PendingProcess $process) use (&$targets) {
                preg_match("/gzip > '([^']+)'/", $this->script($process), $matches);
                // tempnam() already created it, so a second run can never pick the same name.
                $targets[] = [$matches[1], file_exists($matches[1])];
                file_put_contents($matches[1], gzencode($this->completeDump()));

                return Process::result();
            },
            '*which*' => Process::result(),
        ]);

        $this->artisan('db:backup')->assertSuccessful();

        $this->assertCount(1, $targets);
        [$target, $existedBeforeDump] = $targets[0];
        $this->assertTrue($existedBeforeDump);
        $this->assertStringStartsWith('.partial-', basename($target));
        $this->assertSame(['backup-2026-10-01-120000.sql.gz'], $this->backupNames());
        $this->assertNoTemporaryFiles();
    }

    public function test_backup_refuses_while_another_backup_holds_the_lock(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(5);
        $this->fakeDump();

        $lock = fopen("{$this->backupDir}/.backup.lock", 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->artisan('db:backup', ['--retention' => 1, '--weekly-retention' => 0])
                ->assertFailed()
                ->expectsOutputToContain('another db:backup is already running');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        Process::assertNothingRan();
        $this->assertCount(5, $this->backupNames());
    }

    public function test_backup_refuses_to_overwrite_a_backup_taken_in_the_same_second(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(5);
        $existing = "{$this->backupDir}/backup-2026-10-01-120000.sql.gz";
        file_put_contents($existing, 'first run');
        $this->fakeDump();

        $this->artisan('db:backup', ['--retention' => 1, '--weekly-retention' => 0])
            ->assertFailed()
            ->expectsOutputToContain('backup-2026-10-01-120000.sql.gz already exists');

        Process::assertNothingRan();
        $this->assertSame('first run', file_get_contents($existing));
        $this->assertCount(6, $this->backupNames());
        $this->assertNoTemporaryFiles();
    }

    public function test_native_dump_ignores_client_option_files_and_forces_the_completion_trailer(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => (bool) preg_match(
            "#^bash -c set -o pipefail; mariadb-dump --no-defaults -h '127\\.0\\.0\\.9' -P '3399' -u 'crm' --comments --single-transaction --routines --triggers 'crm_db' \\| gzip > '[^']+/\\.partial-[^']+'$#",
            $this->script($process),
        ));
    }

    public function test_docker_dump_forces_the_completion_trailer(): void
    {
        $this->useConnection();
        $this->fakeDump();
        Process::fake([
            '*which*' => Process::result(exitCode: 1),
            '*docker compose ps*' => Process::result(output: "abc123\n"),
        ]);

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_contains(
            $this->script($process),
            "set -o pipefail; docker compose exec -T -e MYSQL_PWD mariadb mariadb-dump -u 'crm' --comments --single-transaction --routines --triggers 'crm_db' | gzip > ",
        ));
    }

    public function test_native_restore_ignores_client_option_files(): void
    {
        $this->runningLocally();
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->seedBackups(1);
        $this->fakeRestore();

        $this->artisan('db:restore', ['--force' => true])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_ends_with(
            $this->script($process),
            "/backup-2026-09-30-120000.sql.gz' | mariadb --no-defaults -h '127.0.0.9' -P '3399' -u 'crm' 'crm_db'",
        ));
    }

    public function test_the_oracle_mysqldump_fallback_also_skips_login_paths(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->fallBackToMysqlClients(supportsNoLoginPaths: true);
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_contains(
            $this->script($process),
            "set -o pipefail; mysqldump --no-defaults --no-login-paths -h '127.0.0.9' -P '3399' -u 'crm' --comments ",
        ));
    }

    public function test_the_oracle_mysql_restore_fallback_also_skips_login_paths(): void
    {
        $this->runningLocally();
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->seedBackups(1);
        $this->fallBackToMysqlClients(supportsNoLoginPaths: true);
        $this->fakeRestore();

        $this->artisan('db:restore', ['--force' => true])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_ends_with(
            $this->script($process),
            "/backup-2026-09-30-120000.sql.gz' | mysql --no-defaults --no-login-paths -h '127.0.0.9' -P '3399' -u 'crm' 'crm_db'",
        ));
    }

    public function test_a_mysql_named_client_without_the_option_gets_only_no_defaults(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->fallBackToMysqlClients(supportsNoLoginPaths: false);
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_contains(
            $this->script($process),
            "set -o pipefail; mysqldump --no-defaults -h '127.0.0.9'",
        ));
    }

    public function test_the_capability_probe_ignores_option_files(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->fallBackToMysqlClients(supportsNoLoginPaths: true);
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => $this->script($process) === 'mysqldump --no-defaults --help');
    }

    public function test_a_failed_capability_probe_stops_the_backup_instead_of_guessing(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->fallBackToMysqlClients(supportsNoLoginPaths: true, probeFails: true);
        $this->fakeDump();

        $this->artisan('db:backup')
            ->expectsOutputToContain('mysqldump --no-defaults --help failed (exit 7')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains($this->script($process), 'gzip >'));
        $this->assertSame([], $this->backupNames());
        $this->assertNoTemporaryFiles();
    }

    public function test_a_failed_capability_probe_stops_the_restore(): void
    {
        $this->runningLocally();
        $this->travelTo('2026-10-01 12:00:00');
        $this->useConnection();
        $this->seedBackups(1);
        $this->fallBackToMysqlClients(supportsNoLoginPaths: true, probeFails: true);
        $this->fakeRestore();

        $this->artisan('db:restore', ['--force' => true])
            ->expectsOutputToContain('mysql --no-defaults --help failed')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains($this->script($process), 'gunzip -c'));
    }

    public function test_backup_and_restore_run_without_a_process_timeout(): void
    {
        $this->runningLocally();
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(1);
        $this->fakeDump();
        Process::fake(['*gunzip -c*' => Process::result()]);

        $this->artisan('db:backup')->assertSuccessful();
        $this->artisan('db:restore', ['--force' => true])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_contains($this->script($process), 'gzip >') && $process->timeout === null);
        Process::assertRan(fn (PendingProcess $process): bool => str_contains($this->script($process), 'gunzip -c') && $process->timeout === null);
    }

    public function test_backup_removes_the_temporary_file_when_the_dump_process_throws(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(5);
        Process::fake([
            '*gzip >*' => function (PendingProcess $process) {
                preg_match("/gzip > '([^']+)'/", $this->script($process), $matches);
                file_put_contents($matches[1], gzencode($this->completeDump()));
                $symfony = new SymfonyProcess(['true']);

                throw new ProcessTimedOutException(
                    new SymfonyProcessTimedOutException($symfony, SymfonyProcessTimedOutException::TYPE_GENERAL),
                    new ProcessResult($symfony),
                );
            },
            '*which*' => Process::result(),
        ]);

        try {
            $this->artisan('db:backup', ['--retention' => 1, '--weekly-retention' => 0])->run();
            $this->fail('The timed-out dump should propagate.');
        } catch (ProcessTimedOutException) {
            // Expected: the command does not swallow it.
        }

        $this->assertNoTemporaryFiles();
        $this->assertCount(5, $this->backupNames());
    }

    public function test_list_shows_backups(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(2);

        $this->artisan('db:backup:list')
            ->assertSuccessful()
            ->expectsOutputToContain('backup-2026-09-30-120000.sql.gz')
            ->expectsOutputToContain('2 backup(s)');
    }

    public function test_list_empty(): void
    {
        $this->artisan('db:backup:list')
            ->assertSuccessful()
            ->expectsOutputToContain('No backups found');
    }

    public function test_restore_with_force_restores_the_latest_backup(): void
    {
        $this->runningLocally();
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(3);
        $this->fakeRestore();

        $this->artisan('db:restore', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Restoring: backup-2026-09-30-120000.sql.gz')
            ->expectsOutputToContain('Database restored successfully');

        Process::assertRan(fn (PendingProcess $process): bool => $this->isRestoreOf($process, 'backup-2026-09-30-120000.sql.gz'));
    }

    public function test_restore_runs_under_pipefail_with_the_password_in_the_environment(): void
    {
        $this->runningLocally();
        config(['database.connections.mariadb.password' => 's3cret-pw']);
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(1);
        $this->fakeRestore();

        $this->artisan('db:restore', ['--force' => true])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => str_starts_with($this->script($process), 'bash -c set -o pipefail; gunzip -c')
            && ! str_contains($this->script($process), 's3cret-pw')
            && ($process->environment['MYSQL_PWD'] ?? null) === 's3cret-pw');
    }

    public function test_restore_specific_file(): void
    {
        $this->runningLocally();
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(3);
        $this->fakeRestore();

        $this->artisan('db:restore', ['file' => 'backup-2026-09-28-120000.sql.gz', '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Database restored successfully');

        Process::assertRan(fn (PendingProcess $process): bool => $this->isRestoreOf($process, 'backup-2026-09-28-120000.sql.gz'));
        Process::assertDidntRun(fn (PendingProcess $process): bool => $this->isRestoreOf($process, 'backup-2026-09-30-120000.sql.gz'));
    }

    public function test_restore_reports_a_failed_restore(): void
    {
        $this->runningLocally();
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(1);
        Process::fake([
            '*gunzip -c*' => Process::result(errorOutput: 'ERROR 1045: access denied', exitCode: 1),
            '*which*' => Process::result(),
        ]);

        $this->artisan('db:restore', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('Restore failed');
    }

    public function test_restore_missing_file_fails(): void
    {
        $this->runningLocally();
        $this->fakeRestore();

        $this->artisan('db:restore', ['file' => 'nonexistent.sql.gz', '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('not found');

        Process::assertNothingRan();
    }

    public function test_restore_no_backups_fails(): void
    {
        $this->runningLocally();
        $this->fakeRestore();

        $this->artisan('db:restore', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('No backups found');

        Process::assertNothingRan();
    }

    public function test_restore_refuses_to_run_outside_the_local_environment_even_with_force(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $this->seedBackups(1);
        $this->fakeRestore();

        $this->artisan('db:restore', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('db:restore only runs in the local environment (current: testing)');

        $this->app['env'] = 'production';

        $this->artisan('db:restore', ['file' => 'backup-2026-09-30-120000.sql.gz', '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('(current: production)');

        Process::assertNothingRan();
    }

    public function test_nightly_backup_is_scheduled_only_in_the_local_environment(): void
    {
        $this->assertFalse($this->scheduleDefinesBackup());

        $this->runningLocally();

        $this->assertTrue($this->scheduleDefinesBackup());
    }

    public function test_a_hosted_stack_schedules_the_nightly_dump_at_its_configured_time(): void
    {
        config(['backup.schedule_at' => '01:15']);

        $event = $this->backupEvent();

        $this->assertNotNull($event);
        $this->assertSame('15 1 * * *', $event->expression);
    }

    public function test_a_malformed_dump_time_is_refused_when_the_schedule_loads_instead_of_breaking_every_tick(): void
    {
        foreach (['0230', '2.30', '24:00', '02:60', '2:30am'] as $value) {
            config(['backup.schedule_at' => $value]);

            try {
                $this->backupEvent();
                $this->fail("BACKUP_SCHEDULE_AT='{$value}' was accepted.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("'{$value}'", $e->getMessage());
            }
        }
    }

    public function test_a_single_digit_dump_hour_still_schedules(): void
    {
        config(['backup.schedule_at' => '2:30']);

        $this->assertSame('30 2 * * *', $this->backupEvent()?->expression);
    }

    public function test_backup_without_any_dump_client_names_the_cause_instead_of_trying_docker(): void
    {
        Process::fake([
            '*which*' => Process::result(exitCode: 1),
            '*docker compose ps*' => Process::result(errorOutput: 'bash: docker: command not found', exitCode: 127),
        ]);

        $this->artisan('db:backup')
            ->assertFailed()
            ->expectsOutputToContain('no mariadb-dump or mysqldump on PATH');

        Process::assertNotRan(fn (PendingProcess $process): bool => str_contains($this->script($process), 'docker compose exec'));
        $this->assertNoTemporaryFiles();
        $this->assertSame([], $this->backupNames());
    }

    private function scheduleDefinesBackup(): bool
    {
        return $this->backupEvent() !== null;
    }

    private function backupEvent(): ?Event
    {
        ScheduleFacade::swap($schedule = new Schedule);
        require base_path('routes/console.php');

        return collect($schedule->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'db:backup'));
    }

    private function useConnection(): void
    {
        config([
            'database.connections.mariadb.host' => '127.0.0.9',
            'database.connections.mariadb.port' => 3399,
            'database.connections.mariadb.username' => 'crm',
            'database.connections.mariadb.database' => 'crm_db',
            'database.connections.mariadb.password' => 'pw',
        ]);
    }

    /**
     * No MariaDB binaries and no running container, so the commands fall back to mysqldump and mysql.
     */
    private function fallBackToMysqlClients(bool $supportsNoLoginPaths, bool $probeFails = false): void
    {
        $help = "--no-defaults           Don't read default options from any option file\n"
            .($supportsNoLoginPaths ? "--no-login-paths        Don't read login paths from the login path file.\n" : '');

        Process::fake([
            '*which*mariadb*' => Process::result(exitCode: 1),
            '*docker compose ps*' => Process::result(output: ''),
            '*which*mysql*' => Process::result(),
            '*mysql*--help*' => $probeFails
                ? Process::result(errorOutput: "mysqldump: [ERROR] unknown variable 'ssl-mode-x=1'.", exitCode: 7)
                : Process::result(output: $help),
        ]);
    }

    private function runningLocally(): void
    {
        $this->app['env'] = 'local';
    }

    /**
     * Fake the dump pipeline: write $sql gzipped into the file the script redirects to, then exit.
     */
    private function fakeDump(?string $sql = null, int $exitCode = 0): void
    {
        $sql ??= $this->completeDump();

        Process::fake([
            '*gzip >*' => function (PendingProcess $process) use ($sql, $exitCode) {
                preg_match("/gzip > '([^']+)'/", $this->script($process), $matches);
                file_put_contents($matches[1], gzencode($sql));

                return Process::result(errorOutput: $exitCode === 0 ? '' : 'dump died', exitCode: $exitCode);
            },
            '*which*' => Process::result(),
        ]);
    }

    private function completeDump(): string
    {
        return "-- MariaDB dump\n".str_repeat("INSERT INTO t VALUES ('".bin2hex(random_bytes(64))."');\n", 8)."-- Dump completed on 2026-10-01 12:00:00\n";
    }

    private function assertNoTemporaryFiles(): void
    {
        $this->assertSame([], glob("{$this->backupDir}/.partial-*"));
    }

    private function fakeRestore(): void
    {
        Process::fake([
            '*gunzip -c*' => Process::result(),
            '*which*' => Process::result(),
        ]);
    }

    private function isRestoreOf(PendingProcess $process, string $filename): bool
    {
        $script = $this->script($process);

        return str_contains($script, 'gunzip -c') && str_contains($script, "/{$filename}'");
    }

    private function script(PendingProcess $process): string
    {
        return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
    }

    /**
     * One backup per day for the $days days before now, at the current time of day.
     */
    private function seedBackups(int $days): void
    {
        for ($i = $days; $i >= 1; $i--) {
            $date = now()->subDays($i)->format('Y-m-d-His');
            file_put_contents("{$this->backupDir}/backup-{$date}.sql.gz", gzencode(str_repeat('x', 200)));
        }
    }

    /**
     * @return list<string> newest first
     */
    private function backupNames(): array
    {
        $names = array_map('basename', glob("{$this->backupDir}/backup-*.sql.gz") ?: []);
        rsort($names);

        return $names;
    }
}

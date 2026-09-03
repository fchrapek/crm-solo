<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class BackupCommandsTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = storage_path('backups');

        if (! is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        config([
            'database.connections.mariadb.database' => env('DB_MARIADB_DATABASE', 'crm_solo'),
            'database.connections.mariadb.host' => env('DB_MARIADB_HOST', '127.0.0.1'),
            // The published port from docker-compose.yaml, not the container's
            // 3306: these tests shell out from the host.
            'database.connections.mariadb.port' => env('DB_MARIADB_PORT', '33061'),
            'database.connections.mariadb.username' => env('DB_MARIADB_USERNAME', 'crm_solo'),
            'database.connections.mariadb.password' => env('DB_MARIADB_PASSWORD', 'secret'),
        ]);
    }

    protected function tearDown(): void
    {
        $files = glob("{$this->backupDir}/backup-*.sql.gz");
        if ($files) {
            array_map('unlink', $files);
        }

        parent::tearDown();
    }

    public function test_backup_creates_file(): void
    {
        $this->artisan('db:backup')
            ->assertSuccessful()
            ->expectsOutputToContain('Backup created');

        $files = glob("{$this->backupDir}/backup-*.sql.gz");
        $this->assertNotEmpty($files);
        $this->assertGreaterThan(100, filesize($files[0]));
    }

    public function test_backup_filename_format(): void
    {
        $this->artisan('db:backup')->assertSuccessful();

        $files = glob("{$this->backupDir}/backup-*.sql.gz");
        $this->assertMatchesRegularExpression(
            '/backup-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz/',
            basename($files[0]),
        );
    }

    public function test_backup_retention_cleans_old_files(): void
    {
        for ($i = 10; $i >= 1; $i--) {
            $date = now()->subDays($i)->format('Y-m-d-His');
            file_put_contents("{$this->backupDir}/backup-{$date}.sql.gz", str_repeat('x', 200));
        }

        $this->artisan('db:backup', ['--retention' => 3, '--weekly-retention' => 0])
            ->assertSuccessful()
            ->expectsOutputToContain('Cleaned');

        $remaining = glob("{$this->backupDir}/backup-*.sql.gz");
        $this->assertCount(3, $remaining);
    }

    public function test_backup_retention_keeps_weekly(): void
    {
        for ($i = 20; $i >= 1; $i--) {
            $date = now()->subDays($i)->format('Y-m-d-His');
            file_put_contents("{$this->backupDir}/backup-{$date}.sql.gz", str_repeat('x', 200));
        }

        $this->artisan('db:backup', ['--retention' => 2, '--weekly-retention' => 2])
            ->assertSuccessful();

        $remaining = glob("{$this->backupDir}/backup-*.sql.gz");
        // 2 daily + up to 2 weekly + 1 new = up to 5
        $this->assertLessThanOrEqual(5, count($remaining));
        $this->assertGreaterThanOrEqual(3, count($remaining));
    }

    public function test_list_shows_backups(): void
    {
        $this->artisan('db:backup')->assertSuccessful();

        $this->artisan('db:backup:list')
            ->assertSuccessful()
            ->expectsOutputToContain('backup-')
            ->expectsOutputToContain('1 backup(s)');
    }

    public function test_list_empty(): void
    {
        $this->artisan('db:backup:list')
            ->assertSuccessful()
            ->expectsOutputToContain('No backups found');
    }

    public function test_restore_with_force_restores_latest(): void
    {
        $this->artisan('db:backup')->assertSuccessful();

        $this->artisan('db:restore', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Database restored successfully');
    }

    public function test_restore_specific_file(): void
    {
        $this->artisan('db:backup')->assertSuccessful();

        $files = glob("{$this->backupDir}/backup-*.sql.gz");
        $filename = basename($files[0]);

        $this->artisan('db:restore', ['file' => $filename, '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Database restored successfully');
    }

    public function test_restore_missing_file_fails(): void
    {
        $this->artisan('db:restore', ['file' => 'nonexistent.sql.gz', '--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('not found');
    }

    public function test_restore_no_backups_fails(): void
    {
        $this->artisan('db:restore', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('No backups found');
    }

    public function test_backup_handles_dump_failure(): void
    {
        Process::fake([
            '*mariadb*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
            '*docker*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
            '*mysqldump*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
            '*which*' => Process::result(exitCode: 1),
        ]);

        $this->artisan('db:backup')
            ->assertFailed()
            ->expectsOutputToContain('Backup failed');
    }
}

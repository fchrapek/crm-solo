<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

final class RestoreDatabase extends Command
{
    protected $signature = 'db:restore
                            {file? : Backup filename to restore (lists available if omitted)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a MariaDB backup';

    public function handle(): int
    {
        $backupDir = storage_path('backups');
        $file = $this->argument('file');

        if (! $file) {
            if ($this->option('force')) {
                $file = $this->latestBackup($backupDir);
            } else {
                $file = $this->pickBackup($backupDir);
            }
            if (! $file) {
                return self::FAILURE;
            }
        }

        $filepath = str_starts_with($file, '/') ? $file : "{$backupDir}/{$file}";

        if (! file_exists($filepath)) {
            $this->error("Backup file not found: {$filepath}");

            return self::FAILURE;
        }

        $database = config('database.connections.mariadb.database');

        if (! $this->option('force') && ! $this->confirm("This will overwrite the '{$database}' database. Continue?")) {
            $this->info('Restore cancelled.');

            return self::SUCCESS;
        }

        $this->info('Restoring: '.basename($filepath));

        $host = config('database.connections.mariadb.host');
        $port = config('database.connections.mariadb.port');
        $username = config('database.connections.mariadb.username');
        $password = config('database.connections.mariadb.password');

        $restoreCommand = $this->buildRestoreCommand($filepath, $host, (string) $port, $username, $password, $database);

        $result = Process::run(['bash', '-c', $restoreCommand]);

        if ($result->failed()) {
            $this->error('Restore failed: '.$result->errorOutput());

            return self::FAILURE;
        }

        $this->info('Database restored successfully.');

        return self::SUCCESS;
    }

    private function latestBackup(string $backupDir): ?string
    {
        $files = glob("{$backupDir}/backup-*.sql.gz");

        if (! $files) {
            $this->error('No backups found in '.$backupDir);

            return null;
        }

        rsort($files);

        return basename($files[0]);
    }

    private function pickBackup(string $backupDir): ?string
    {
        $files = glob("{$backupDir}/backup-*.sql.gz");

        if (! $files) {
            $this->error('No backups found in '.$backupDir);

            return null;
        }

        rsort($files);

        $choices = array_map(function ($file) {
            $size = $this->formatBytes(filesize($file) ?: 0);

            return basename($file)." ({$size})";
        }, $files);

        $selected = $this->choice('Select a backup to restore', $choices);

        return preg_replace('/\s+\(.+\)$/', '', $selected);
    }

    private function buildRestoreCommand(string $filepath, string $host, string $port, string $username, string $password, string $database): string
    {
        $clientBin = $this->findClientBinary();

        if ($clientBin === 'docker') {
            return sprintf(
                'gunzip -c %s | docker compose exec -T mariadb mariadb -u %s -p%s %s',
                escapeshellarg($filepath),
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($database),
            );
        }

        $args = sprintf(
            '-h %s -P %s -u %s -p%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
        );

        return sprintf('gunzip -c %s | %s %s', escapeshellarg($filepath), $clientBin, $args);
    }

    private function findClientBinary(): string
    {
        $result = Process::run(['which', 'mariadb']);
        if ($result->successful()) {
            return 'mariadb';
        }

        $result = Process::run(['bash', '-c', 'docker compose ps mariadb --status running -q 2>/dev/null']);
        if ($result->successful() && mb_trim($result->output()) !== '') {
            return 'docker';
        }

        $result = Process::run(['which', 'mysql']);
        if ($result->successful()) {
            return 'mysql';
        }

        return 'docker';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 2).' '.$units[$i];
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

final class BackupDatabase extends Command
{
    protected $signature = 'db:backup
                            {--retention=7 : Number of daily backups to keep}
                            {--weekly-retention=4 : Number of weekly backups to keep}';

    protected $description = 'Create a compressed MariaDB backup';

    public function handle(): int
    {
        $backupDir = storage_path('backups');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = Carbon::now()->format('Y-m-d-His');
        $filename = "backup-{$timestamp}.sql.gz";
        $filepath = "{$backupDir}/{$filename}";

        $host = config('database.connections.mariadb.host');
        $port = config('database.connections.mariadb.port');
        $database = config('database.connections.mariadb.database');
        $username = config('database.connections.mariadb.username');
        $password = config('database.connections.mariadb.password');

        $this->info("Creating backup: {$filename}");

        $dumpCommand = $this->buildDumpCommand($host, (string) $port, $username, $password, $database, $filepath);

        $result = Process::run(['bash', '-c', $dumpCommand]);

        if ($result->failed() || (file_exists($filepath) && filesize($filepath) < 100)) {
            $this->error('Backup failed: '.($result->errorOutput() ?: 'Empty dump file'));

            if (file_exists($filepath)) {
                unlink($filepath);
            }

            return self::FAILURE;
        }

        $size = $this->formatBytes(filesize($filepath) ?: 0);
        $this->info("Backup created: {$filename} ({$size})");

        $cleaned = $this->cleanOldBackups($backupDir);
        if ($cleaned > 0) {
            $this->info("Cleaned {$cleaned} old backup(s).");
        }

        return self::SUCCESS;
    }

    private function cleanOldBackups(string $backupDir): int
    {
        $dailyRetention = (int) $this->option('retention');
        $weeklyRetention = (int) $this->option('weekly-retention');

        $files = glob("{$backupDir}/backup-*.sql.gz");
        if (! $files) {
            return 0;
        }

        rsort($files);

        $keep = [];
        $weeklySundays = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (! preg_match('/backup-(\d{4}-\d{2}-\d{2})-\d{6}\.sql\.gz/', $basename, $matches)) {
                continue;
            }

            $date = Carbon::parse($matches[1]);

            if (count(array_filter($keep, fn ($k) => $k['type'] === 'daily')) < $dailyRetention) {
                $keep[] = ['file' => $file, 'type' => 'daily'];

                continue;
            }

            $sunday = $date->copy()->startOfWeek(Carbon::SUNDAY)->format('Y-m-d');
            if (! in_array($sunday, $weeklySundays) && count($weeklySundays) < $weeklyRetention) {
                $weeklySundays[] = $sunday;
                $keep[] = ['file' => $file, 'type' => 'weekly'];

                continue;
            }
        }

        $keepFiles = array_column($keep, 'file');
        $cleaned = 0;

        foreach ($files as $file) {
            if (! in_array($file, $keepFiles)) {
                unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    private function buildDumpCommand(string $host, string $port, string $username, string $password, string $database, string $filepath): string
    {
        $dumpBin = $this->findDumpBinary();

        if ($dumpBin === 'docker') {
            return sprintf(
                'docker compose exec -T mariadb mariadb-dump -u %s -p%s --single-transaction --routines --triggers %s | gzip > %s',
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($database),
                escapeshellarg($filepath),
            );
        }

        $args = sprintf(
            '-h %s -P %s -u %s -p%s --single-transaction --routines --triggers %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
        );

        return sprintf('%s %s | gzip > %s', $dumpBin, $args, escapeshellarg($filepath));
    }

    private function findDumpBinary(): string
    {
        $result = Process::run(['which', 'mariadb-dump']);
        if ($result->successful()) {
            return 'mariadb-dump';
        }

        $result = Process::run(['bash', '-c', 'docker compose ps mariadb --status running -q 2>/dev/null']);
        if ($result->successful() && mb_trim($result->output()) !== '') {
            return 'docker';
        }

        $result = Process::run(['which', 'mysqldump']);
        if ($result->successful()) {
            return 'mysqldump';
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

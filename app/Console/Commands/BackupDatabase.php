<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use RuntimeException;

#[AccountScope(AccountScope::OPERATOR)]
final class BackupDatabase extends Command
{
    use Concerns\IgnoresClientOptionFiles;

    protected $signature = 'db:backup
                            {--retention=7 : Number of daily backups to keep}
                            {--weekly-retention=4 : Number of weekly backups to keep}';

    protected $description = 'Create a compressed MariaDB backup (operator: the whole database, every account)';

    public function handle(): int
    {
        $backupDir = config('backup.path');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // One run at a time: a second run would race the first for the same names and prune under it.
        $lock = fopen("{$backupDir}/.backup.lock", 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Backup failed: another db:backup is already running.');

            return self::FAILURE;
        }

        try {
            return $this->backUp($backupDir);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function backUp(string $backupDir): int
    {
        $timestamp = Carbon::now()->format('Y-m-d-His');
        $filename = "backup-{$timestamp}.sql.gz";
        $filepath = "{$backupDir}/{$filename}";

        if (file_exists($filepath)) {
            $this->error("Backup failed: {$filename} already exists.");

            return self::FAILURE;
        }

        // Exclusively created and outside the backup glob; renamed into place only once the dump is verified.
        $partialPath = tempnam($backupDir, '.partial-');
        if ($partialPath === false || realpath(dirname($partialPath)) !== realpath($backupDir)) {
            if ($partialPath !== false) {
                unlink($partialPath);
            }
            $this->error("Backup failed: could not create a temporary file in {$backupDir}.");

            return self::FAILURE;
        }

        $host = config('database.connections.mariadb.host');
        $port = config('database.connections.mariadb.port');
        $database = config('database.connections.mariadb.database');
        $username = config('database.connections.mariadb.username');
        $password = (string) config('database.connections.mariadb.password');

        $this->info("Creating backup: {$filename}");

        $published = false;

        try {
            try {
                $dumpCommand = $this->buildDumpCommand($host, (string) $port, $username, $database, $partialPath);
            } catch (RuntimeException $e) {
                $this->error('Backup failed: '.$e->getMessage());

                return self::FAILURE;
            }

            // No timeout: a large dump legitimately runs for minutes.
            $result = Process::forever()->env(['MYSQL_PWD' => $password])->run(['bash', '-c', $dumpCommand]);

            $error = match (true) {
                $result->failed() => $result->errorOutput() ?: 'dump exited with code '.$result->exitCode(),
                ! file_exists($partialPath) || filesize($partialPath) < 100 => 'Empty dump file',
                ! $this->dumpIsComplete($partialPath) => 'Dump is incomplete (no "-- Dump completed" trailer)',
                default => null,
            };

            if ($error !== null || ! rename($partialPath, $filepath)) {
                $this->error('Backup failed: '.($error ?? 'could not move the dump into place'));

                return self::FAILURE;
            }

            $published = true;
        } finally {
            // Also runs when the process throws, so an unpublished dump never stays behind.
            if (! $published && file_exists($partialPath)) {
                unlink($partialPath);
            }
        }

        $size = $this->formatBytes(filesize($filepath) ?: 0);
        $this->info("Backup created: {$filename} ({$size})");

        $cleaned = $this->cleanOldBackups($backupDir);
        if ($cleaned > 0) {
            $this->info("Cleaned {$cleaned} old backup(s).");
        }

        return self::SUCCESS;
    }

    /**
     * mariadb-dump and mysqldump end every finished dump with this trailer, so its absence means a cut-off dump.
     */
    private function dumpIsComplete(string $path): bool
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return false;
        }

        $tail = '';
        while (! gzeof($gz)) {
            $chunk = gzread($gz, 1024 * 1024);
            if ($chunk === false) {
                gzclose($gz);

                return false;
            }
            $tail = mb_substr($tail.$chunk, -4096);
        }
        gzclose($gz);

        return str_contains($tail, '-- Dump completed');
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

    /**
     * The password travels in MYSQL_PWD, never on the command line; pipefail fails the run when either side of the pipe does.
     * --no-defaults (it must come first) keeps a ~/.my.cnf password or skip-comments from overriding these settings,
     * and --comments is explicit because the completion check reads the trailer it writes.
     */
    private function buildDumpCommand(string $host, string $port, string $username, string $database, string $filepath): string
    {
        $dumpBin = $this->findDumpBinary();

        if ($dumpBin === 'docker') {
            return sprintf(
                'set -o pipefail; docker compose exec -T -e MYSQL_PWD mariadb mariadb-dump -u %s --comments --single-transaction --routines --triggers %s | gzip > %s',
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($filepath),
            );
        }

        $args = sprintf(
            '%s -h %s -P %s -u %s --comments --single-transaction --routines --triggers %s',
            $this->optionFileArgs($dumpBin),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
        );

        return sprintf('set -o pipefail; %s %s | gzip > %s', $dumpBin, $args, escapeshellarg($filepath));
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

        // Inside a container there is no compose project to exec into, so guessing docker only hides the real cause.
        throw new RuntimeException('no mariadb-dump or mysqldump on PATH and no running docker compose mariadb service. Install the MariaDB client (the hosting image ships it).');
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

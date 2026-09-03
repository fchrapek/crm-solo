<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class ListBackups extends Command
{
    protected $signature = 'db:backup:list';

    protected $description = 'List available database backups';

    public function handle(): int
    {
        $backupDir = storage_path('backups');
        $files = glob("{$backupDir}/backup-*.sql.gz");

        if (! $files) {
            $this->info('No backups found.');

            return self::SUCCESS;
        }

        rsort($files);

        $rows = array_map(function ($file) {
            $basename = basename($file);
            $size = $this->formatBytes(filesize($file) ?: 0);

            preg_match('/backup-(\d{4}-\d{2}-\d{2})-(\d{6})\.sql\.gz/', $basename, $matches);
            $age = $matches ? Carbon::parse($matches[1].' '.implode(':', mb_str_split($matches[2], 2)))->diffForHumans() : '-';

            return [$basename, $size, $age];
        }, $files);

        $this->table(['Filename', 'Size', 'Age'], $rows);

        $totalSize = array_sum(array_map('filesize', $files));
        $this->info(count($files).' backup(s), '.$this->formatBytes($totalSize).' total');

        return self::SUCCESS;
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

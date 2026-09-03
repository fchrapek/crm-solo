<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Repository;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

final class CleanupSessionWorktrees extends Command
{
    protected $signature = 'sessions:cleanup {--prune : Remove eligible worktrees (default is a dry-run listing)}';

    protected $description = 'List (and with --prune remove) session worktrees for archived/completed tasks — each is a full checkout that otherwise sits on disk forever';

    public function handle(): int
    {
        $rows = [];
        $eligible = [];

        Repository::query()
            ->whereNotNull('local_path')
            ->get()
            ->filter(fn (Repository $repo): bool => is_dir((string) $repo->local_path))
            ->each(function (Repository $repo) use (&$rows, &$eligible): void {
                foreach (glob($repo->local_path.'/.worktrees/task-*') ?: [] as $path) {
                    if (! is_dir($path)) {
                        continue;
                    }
                    $taskId = (int) str_replace('task-', '', basename($path));
                    $task = Task::find($taskId);

                    $sessionLive = $task !== null
                        && $task->session_pid !== null
                        && posix_kill((int) $task->session_pid, 0);
                    $done = $task === null || $task->archived_at !== null || (bool) $task->is_completed;
                    $dirty = mb_trim(Process::path($path)->run(['git', 'status', '--porcelain'])->output()) !== '';

                    $state = match (true) {
                        $sessionLive => 'session live — kept',
                        ! $done => 'task open — kept',
                        default => 'eligible',
                    };
                    $rows[] = [
                        $repo->name,
                        basename($path),
                        $task?->name ?? '(task deleted)',
                        $state,
                        $dirty ? 'dirty' : 'clean',
                    ];
                    if ($state === 'eligible') {
                        $eligible[] = ['repo' => $repo->local_path, 'path' => $path];
                    }
                }
            });

        if ($rows === []) {
            $this->info('No session worktrees found.');

            return self::SUCCESS;
        }

        $this->table(['Repository', 'Worktree', 'Task', 'State', 'Tree'], $rows);

        if (! $this->option('prune')) {
            $this->info(count($eligible).' eligible for removal. Re-run with --prune to remove them.');

            return self::SUCCESS;
        }

        foreach ($eligible as $item) {
            // Non-force remove: git itself refuses dirty/untracked worktrees,
            // so uncommitted agent work is never destroyed. The session branch
            // is intentionally kept — it may hold unmerged commits; deleting
            // branches stays a human decision.
            $result = Process::path($item['repo'])->run(['git', 'worktree', 'remove', $item['path']]);
            if ($result->successful()) {
                $this->info('Removed '.$item['path']);
            } else {
                $this->warn('Skipped '.$item['path'].' — '.mb_trim($result->errorOutput()));
            }
        }

        Repository::query()
            ->whereNotNull('local_path')
            ->get()
            ->filter(fn (Repository $repo): bool => is_dir((string) $repo->local_path.'/.git'))
            ->each(function (Repository $repo): void {
                Process::path((string) $repo->local_path)->run(['git', 'worktree', 'prune']);
            });

        return self::SUCCESS;
    }
}

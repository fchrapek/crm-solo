<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\Task;
use App\Models\TaskPreview;
use App\Services\TerminalSessionLauncherInterface;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::OPERATOR)]
final class ReapDeadSessions extends Command
{
    protected $signature = 'sessions:reap';

    protected $description = 'Close terminal sessions (and previews) whose ttyd process died without a Stop — reboot/crash leaves a billable TimeEntry accruing forever otherwise (operator: every account)';

    public function handle(TerminalSessionLauncherInterface $launcher): int
    {
        $reaped = 0;

        Task::query()
            ->whereNotNull('session_pid')
            ->each(function (Task $task) use ($launcher, &$reaped): void {
                if ($this->processAlive((int) $task->session_pid)) {
                    return;
                }
                // stop() on a dead PID skips the signal, closes the TimeEntry
                // at now() and records the TaskSession as crashed. Scheduled
                // cadence bounds the billing drift to one interval.
                $launcher->stop($task);
                $reaped++;
                $this->info("Reaped dead session on task #{$task->id} ({$task->name})");
            });

        TaskPreview::query()
            ->whereNotNull('pid')
            ->whereNull('stopped_at')
            ->each(function (TaskPreview $preview) use (&$reaped): void {
                if ($this->processAlive((int) $preview->pid)) {
                    return;
                }
                $preview->update([
                    'pid' => null,
                    'port' => null,
                    'stopped_at' => now(),
                    'stopped_reason' => TaskPreview::STOPPED_CRASHED,
                ]);
                $reaped++;
                $this->info("Reaped dead preview on task #{$preview->task_id}");
            });

        $this->info($reaped === 0 ? 'No dead sessions.' : "Reaped {$reaped} dead session(s).");

        return self::SUCCESS;
    }

    private function processAlive(int $pid): bool
    {
        return $pid > 0 && posix_kill($pid, 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Terminal\PreviewNotConfiguredException;
use App\Exceptions\Terminal\RepositoryMissingException;
use App\Exceptions\Terminal\WorktreeMissingException;
use App\Models\Task;
use App\Models\TaskPreview;
use App\Services\Concerns\ManagesTtydProcess;
use RuntimeException;

final class ProjectPreviewLauncher implements ProjectPreviewLauncherInterface
{
    use ManagesTtydProcess;

    public function start(Task $task): TaskPreview
    {
        $project = $task->project;
        if ($project === null) {
            throw new RuntimeException('Task has no project.');
        }
        $command = mb_trim((string) $project->preview_command);
        if ($command === '') {
            throw new PreviewNotConfiguredException('Project has no preview_command configured.');
        }

        $repo = $project->repositories()->first();
        if ($repo === null || $repo->local_path === null || $repo->local_path === '') {
            throw new RepositoryMissingException('Project has no repository with a local path.');
        }

        // Preview only makes sense in the agent's worktree (so unstaged WIP
        // shows up). Require the worktree to already exist — if it doesn't,
        // the user needs to start a session first to provision it.
        $worktreePath = $task->sessionWorktreePath($repo->local_path);
        if (! is_dir($worktreePath)) {
            throw new WorktreeMissingException("Worktree does not exist yet at {$worktreePath} — start a session for this task first.");
        }

        $workingDir = $this->resolveWorkingDir($worktreePath, (string) $project->preview_working_dir);

        // Idempotent: if a preview is already live for this task with an alive
        // ttyd, return it. Caller (controller) handles the per-project mutex
        // case before getting here.
        $existing = $task->runningPreview();
        if ($existing !== null && $existing->pid !== null && $this->isProcessAlive($existing->pid)) {
            return $existing;
        }
        // If a row is "running" in the DB but the PID is dead, close it as
        // crashed and start fresh.
        if ($existing !== null) {
            $existing->update([
                'stopped_at' => now(),
                'stopped_reason' => TaskPreview::STOPPED_CRASHED,
            ]);
        }

        $preview = TaskPreview::create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'account_id' => $project->account_id,
            'command' => $command,
            'working_dir' => $workingDir,
            'url' => $project->preview_url,
            'started_at' => now(),
        ]);

        $logPath = storage_path('logs/preview-task-'.$task->id.'.log');
        [$port, $pid] = $this->spawnTtyd($workingDir, $command, $task->id, $logPath);
        $preview->update([
            'port' => $port,
            'pid' => $pid,
            'log_path' => $logPath,
        ]);

        return $preview->fresh();
    }

    public function stop(Task $task): void
    {
        $preview = $task->runningPreview();
        if ($preview === null) {
            return;
        }

        $wasAlive = $preview->pid !== null && $this->isProcessAlive($preview->pid);
        if ($wasAlive) {
            posix_kill((int) $preview->pid, SIGTERM);
        }
        $tmux = $this->locateTmux(throwIfMissing: false);
        if ($tmux !== null) {
            $this->run([$tmux, 'kill-session', '-t', $this->tmuxSessionName($task->id)]);
        }

        $preview->update([
            'stopped_at' => now(),
            'stopped_reason' => $wasAlive ? TaskPreview::STOPPED_NORMAL : TaskPreview::STOPPED_CRASHED,
        ]);
    }

    public function tmuxSessionAlive(Task $task): bool
    {
        $tmux = $this->locateTmux(throwIfMissing: false);
        if ($tmux === null) {
            return false;
        }

        return $this->run([$tmux, 'has-session', '-t', $this->tmuxSessionName($task->id)])->isSuccessful();
    }

    private function resolveWorkingDir(string $worktreePath, string $relative): string
    {
        $relative = mb_trim($relative, "/ \t\n\r\0\x0B");
        if ($relative === '' || $relative === '.') {
            return $worktreePath;
        }

        return mb_rtrim($worktreePath, '/').'/'.$relative;
    }

    /**
     * Spawn ttyd wrapping the preview command in a tmux session, just like the
     * agent terminal session does. tmux lets the user reconnect across iframe
     * unmount/remount without losing the dev server's output.
     *
     * Different tmux session name prefix (`crm-preview-<id>` vs.
     * `crm-task-<id>`) so the preview pane and the agent CLI pane never
     * collide.
     *
     * @return array{0: int, 1: int}
     */
    private function spawnTtyd(string $workingDir, string $command, int $taskId, string $logPath): array
    {
        $ttyd = $this->locateTtyd();
        $tmux = $this->locateTmux();
        $port = $this->allocateFreePort();
        $sessionName = $this->tmuxSessionName($taskId);

        $tmuxBin = escapeshellarg($tmux);
        $sessionArg = escapeshellarg($sessionName);
        $workingDirArg = escapeshellarg($workingDir);
        // Wrap the user's command so any inner long-running process gets
        // killed when the user exits the tmux pane; falls through to bash
        // afterwards so the user can rerun manually or inspect output.
        $userCmd = escapeshellarg($command.'; echo; echo "[preview command exited — type to exit pane]"; exec bash');

        // history-limit + mouse: trackpad scrollback through agent output.
        // status off: chrome-free terminal matching the agent session.
        $innerCmd = sprintf(
            'if ! %s has-session -t %s 2>/dev/null; then '.
            '  %s set-option -g history-limit 50000 >/dev/null; '.
            '  %s new-session -d -s %s -c %s; '.
            '  %s set-option -t %s status off >/dev/null; '.
            '  %s set-option -t %s mouse on >/dev/null; '.
            '  %s send-keys -t %s -- %s C-m; '.
            'fi; '.
            'exec %s attach -t %s',
            $tmuxBin, $sessionArg,
            $tmuxBin,
            $tmuxBin, $sessionArg, $workingDirArg,
            $tmuxBin, $sessionArg,
            $tmuxBin, $sessionArg,
            $tmuxBin, $sessionArg, $userCmd,
            $tmuxBin, $sessionArg,
        );
        $ttydCmd = sprintf(
            '%s -p %d -i 127.0.0.1 -W -O -t titleFixed=preview-%d %s -lc %s',
            escapeshellarg($ttyd),
            $port,
            $taskId,
            escapeshellarg('/bin/bash'),
            escapeshellarg($innerCmd),
        );

        // Detached spawn with env-i credential stripping — see
        // ManagesTtydProcess::spawnDetachedTtyd. Same foot-gun as the agent
        // terminal: the preview's dev server must not see CRM credentials.
        $pid = $this->spawnDetachedTtyd($ttydCmd, $logPath, 'Preview ttyd');

        return [$port, $pid];
    }

    private function tmuxSessionName(int $taskId): string
    {
        return 'crm-preview-'.$taskId;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\TaskPreview;

interface ProjectPreviewLauncherInterface
{
    /**
     * Spawn `projects.preview_command` in the task's worktree (under
     * `projects.preview_working_dir`, defaulting to repo root) via ttyd+tmux.
     * Records a `task_previews` row + populates `pid`/`port` on it.
     *
     * Idempotent — calling on a task with a live preview returns the existing
     * row. Caller is responsible for the per-project mutex check (controller).
     *
     * @return TaskPreview the running preview row
     */
    public function start(Task $task): TaskPreview;

    /**
     * Stop the task's live preview: SIGTERM the ttyd pid, kill the tmux
     * session, close the row with stopped_reason='stopped' (or 'crashed' if
     * the pid was already gone). Idempotent — no-op if nothing's running.
     */
    public function stop(Task $task): void;

    /**
     * Whether the preview's tmux session is alive on the host. Used by
     * controller to detect stale rows whose ttyd died unexpectedly.
     */
    public function tmuxSessionAlive(Task $task): bool;
}

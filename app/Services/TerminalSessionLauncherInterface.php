<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;

interface TerminalSessionLauncherInterface
{
    /**
     * Launch a terminal session. $mode picks between:
     *   - 'worktree' (Task::SESSION_MODE_WORKTREE): isolated git worktree at
     *     <repo>/.worktrees/task-{id}/. Multiple tasks per repo can run in
     *     parallel; existing portability gotchas apply (env/wp-config/
     *     node_modules not copied, etc.).
     *   - 'in_repo' (Task::SESSION_MODE_IN_REPO): checkout session/task-{id}
     *     directly in the main repo working tree. Refuses to start if the
     *     working tree is dirty or if another in-repo session is live on
     *     the same repo (per-repo mutex).
     *
     * Returned `session_path` is the directory the terminal is rooted at
     * (the worktree for worktree-mode, the repo root for in-repo).
     *
     * `$cliModel` (nullable) forwards a model alias/id to the CLI's
     * `--model` (claude) / `-m` (codex) flag at launch time. Per-launch
     * only — not persisted to Task. Null leaves the CLI on its own
     * default. Has no effect on resume() (tmux session already holds the
     * running CLI process).
     *
     * @return array{session_path: string, branch_name: string, base_branch: string, port: int, pid: int, mode: string}
     */
    public function launch(Task $task, string $baseBranch, string $mode = Task::SESSION_MODE_WORKTREE, ?string $cliModel = null): array;

    public function stop(Task $task): void;

    /**
     * Destructively tear down everything for this task: kill the tmux session
     * (discarding scrollback + the in-memory CLI process) then run the same
     * cleanup as stop(). Use when the user explicitly wants the conversation
     * gone, not for routine End Session.
     */
    public function kill(Task $task): void;

    /**
     * Reattach a fresh ttyd to the still-alive tmux session for this task.
     * Use after stop() to pick the session back up with full scrollback.
     * Throws if the tmux session no longer exists (RuntimeException) or the
     * task isn't CLI-configured (InvalidArgumentException).
     *
     * @return array{session_path: string, branch_name: string, port: int, pid: int, mode: string}
     */
    public function resume(Task $task): array;

    /**
     * Whether the task's tmux session is alive on the host. Drives the UI's
     * decision to show Resume vs. only Start on a task with closed history.
     */
    public function tmuxSessionAlive(Task $task): bool;

    /**
     * List local branches available in the project's primary repository, plus
     * the repo's stated default (`origin/HEAD` if set) and currently-checked-
     * out branch. Used by the Start Session dialog so the user explicitly
     * picks where to fork the worktree from.
     *
     * Throws RuntimeException if there's no repo with a local path — caller
     * (controller) catches this and surfaces the same `repository_missing`
     * code as launch() so the same RepositoryFormDialog flow kicks in.
     *
     * @return array{branches: array<int, string>, default_branch: ?string, current_branch: ?string}
     */
    public function listSessionBranches(Task $task): array;
}

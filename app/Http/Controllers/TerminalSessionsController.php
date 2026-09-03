<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Terminal\TerminalActionException;
use App\Models\Task;
use App\Services\TerminalSessionLauncherInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class TerminalSessionsController extends Controller
{
    public function __construct(
        private readonly TerminalSessionLauncherInterface $launcher,
    ) {}

    public function start(Request $request, Task $task): JsonResponse
    {
        if ($task->cli === null) {
            abort(422, 'Task has no CLI configured.');
        }

        // base_branch is required: every session forks from an explicitly
        // chosen branch. Filip wants this as a forcing function to keep
        // project branch state intentional. Resume of an already-running
        // session bypasses this controller (frontend just navigates to the
        // task page since the launcher's port survives).
        $validated = $request->validate([
            'base_branch' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/\-]+$/'],
            'mode' => ['nullable', 'string', 'in:'.implode(',', Task::SESSION_MODES)],
            // Loose validation on purpose — the dialog's preset list is
            // frontend-only and the CLIs accept aliases that grow over time
            // (opus / sonnet aliases roll to new versions). The regex keeps
            // it shell-safe; the launcher escapeshellarg's it anyway.
            'cli_model' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ]);
        $baseBranch = $validated['base_branch'];
        $mode = $validated['mode'] ?? Task::SESSION_MODE_WORKTREE;
        $cliModel = $validated['cli_model'] ?? null;

        try {
            $launch = $this->launcher->launch($task, $baseBranch, $mode, $cliModel);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        } catch (TerminalActionException $e) {
            // Structured error so the frontend can pick the right surface —
            // repo-config issues open the themed RepositoryFormDialog; other
            // failures (ttyd missing, git worktree failed, …) just toast.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'launcher_error',
            ], 409);
        }

        if ($task->agent_lane === Task::AGENT_LANE_BACKLOG || $task->agent_lane === null) {
            $task->update(['agent_lane' => Task::AGENT_LANE_IN_PROGRESS]);
        }

        return response()->json($launch);
    }

    public function stop(Task $task): JsonResponse
    {
        $this->launcher->stop($task);

        return response()->json(['stopped' => true]);
    }

    /**
     * Reattach to a previously-stopped tmux session. Resume succeeds only if
     * the tmux session is still alive (typical: stop() doesn't kill it). The
     * launcher decides whether to spawn a fresh ttyd or return the live port
     * if one is already running.
     */
    public function resume(Task $task): JsonResponse
    {
        if ($task->cli === null) {
            abort(422, 'Task has no CLI configured.');
        }

        try {
            $result = $this->launcher->resume($task);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        } catch (TerminalActionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'launcher_error',
            ], 409);
        }

        // Resuming a session also re-engages the agent lane in case End put
        // the user mentally back at backlog. Mirrors start()'s lane handling.
        if ($task->agent_lane === Task::AGENT_LANE_BACKLOG || $task->agent_lane === null) {
            $task->update(['agent_lane' => Task::AGENT_LANE_IN_PROGRESS]);
        }

        return response()->json($result);
    }

    /**
     * Destructive teardown: kills tmux (discards scrollback + the in-memory
     * CLI process). Use when the user explicitly wants the session gone.
     * Routine End Session uses stop() instead, which leaves tmux alive.
     */
    public function kill(Task $task): JsonResponse
    {
        $this->launcher->kill($task);

        return response()->json(['killed' => true]);
    }

    /**
     * List branches available in the task's project repository so the user
     * can pick where to fork the new worktree from. Filip wants this picker
     * forced on every session start — the dialog refuses to launch without
     * a conscious selection.
     *
     * Returns the same `repository_missing` code as start() when the project
     * has no repo, so the frontend can route into the RepositoryFormDialog
     * with the existing auto-retry chain.
     */
    public function branches(Task $task): JsonResponse
    {
        if ($task->cli === null) {
            abort(422, 'Task has no CLI configured.');
        }

        try {
            $payload = $this->launcher->listSessionBranches($task);
        } catch (TerminalActionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'launcher_error',
            ], 409);
        }

        return response()->json($payload);
    }

    /**
     * Mark the user has acknowledged a pending session-attention signal. The
     * frontend calls this when the user visits the task page — at that point
     * the kanban "waiting" badge should clear since the user is now looking
     * at the session.
     */
    public function clearAttention(Task $task): JsonResponse
    {
        if ($task->session_attention_at !== null) {
            $task->forceFill(['session_attention_at' => null])->save();
        }

        return response()->json(['cleared' => true]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Terminal\TerminalActionException;
use App\Models\Task;
use App\Services\ProjectPreviewLauncherInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class TaskPreviewsController extends Controller
{
    public function __construct(
        private readonly ProjectPreviewLauncherInterface $launcher,
    ) {}

    public function start(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);
        $force = (bool) ($validated['force'] ?? false);

        $project = $task->project;
        if ($project === null) {
            abort(422, 'Task has no project.');
        }
        if (mb_trim((string) $project->preview_command) === '') {
            return response()->json([
                'message' => 'No preview command configured for this project.',
                'code' => 'preview_not_configured',
            ], 409);
        }

        // Per-project mutex: only one preview at a time per project (DDEV-style
        // singletons would step on each other; Node-style would race ports). If
        // another task is already serving, the frontend shows a ConfirmDialog
        // and re-requests with force=true to stop the other first.
        $conflict = $project->previews()
            ->whereNull('stopped_at')
            ->where('task_id', '!=', $task->id)
            ->with('task:id,name')
            ->latest('started_at')
            ->first();
        if ($conflict !== null) {
            if (! $force) {
                return response()->json([
                    'message' => 'Another preview is already running for this project.',
                    'code' => 'project_busy',
                    'conflicting_task' => [
                        'id' => $conflict->task_id,
                        'name' => $conflict->task?->name,
                    ],
                ], 409);
            }
            // Stop the conflicting one first, then claim the slot.
            $other = Task::find($conflict->task_id);
            if ($other !== null) {
                $this->launcher->stop($other);
            }
        }

        try {
            $preview = $this->launcher->start($task);
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

        return response()->json([
            'preview' => [
                'id' => $preview->id,
                'port' => $preview->port,
                'pid' => $preview->pid,
                'url' => $preview->url,
                'command' => $preview->command,
                'working_dir' => $preview->working_dir,
                'started_at' => $preview->started_at?->toIso8601String(),
            ],
        ]);
    }

    public function stop(Task $task): JsonResponse
    {
        $this->launcher->stop($task);

        return response()->json(['stopped' => true]);
    }
}

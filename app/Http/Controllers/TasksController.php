<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class TasksController extends Controller
{
    private const MANUAL_STATUSES = ['Backlog', 'To-Do', 'Doing', 'Testing', 'Done'];

    /**
     * When a task is dragged on the agent kanban, mirror its lane onto the
     * Trello-style status so the per-project view stays in sync. Unmapped
     * lanes leave the status untouched.
     */
    private const AGENT_LANE_TO_LIST_NAME = [
        Task::AGENT_LANE_BACKLOG => 'To-Do',
        Task::AGENT_LANE_IN_PROGRESS => 'Doing',
        Task::AGENT_LANE_IN_REVIEW => 'Testing',
        Task::AGENT_LANE_DONE => 'Done',
    ];

    public function store(Request $request, Project $project): RedirectResponse
    {
        if ($project->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:500',
            'description' => 'nullable|string|max:50000',
            'list_name' => ['nullable', 'string', 'in:'.implode(',', self::MANUAL_STATUSES)],
            'due_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
            'parent_task_id' => 'nullable|integer',
            'cli' => ['nullable', Rule::in(Task::CLIS)],
            'is_reportable' => 'nullable|boolean',
        ]);
        $parentTaskId = $this->resolveParentTaskId($project, $validated['parent_task_id'] ?? null);

        Task::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'list_name' => $validated['list_name'] ?? 'To-Do',
            'due_date' => $validated['due_date'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'parent_task_id' => $parentTaskId,
            'cli' => $validated['cli'] ?? null,
            'source' => 'manual',
            'is_reviewed' => true,
            'is_reportable' => (bool) ($validated['is_reportable'] ?? false),
            'is_completed' => ($validated['list_name'] ?? null) === 'Done',
        ]);

        return back()->with('success', __('Task created.'));
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTask($task);

        if ($task->source === 'trello') {
            abort(422, 'Trello-source tasks must be edited on the Trello board.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:500',
            'description' => 'nullable|string|max:50000',
            'list_name' => ['nullable', 'string', 'in:'.implode(',', self::MANUAL_STATUSES)],
            'due_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
            'parent_task_id' => 'nullable|integer',
            'cli' => ['nullable', Rule::in(Task::CLIS)],
            'is_reportable' => 'nullable|boolean',
        ]);

        $wasCompleted = $task->is_completed;
        $newListName = $validated['list_name'] ?? $task->list_name;
        $isCompleted = $newListName === 'Done';
        $parentTaskId = array_key_exists('parent_task_id', $validated)
            ? $this->resolveParentTaskId($task->project, $validated['parent_task_id'] ?? null, $task)
            : $task->parent_task_id;

        $update = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'list_name' => $newListName,
            'due_date' => $validated['due_date'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'parent_task_id' => $parentTaskId,
            'is_completed' => $isCompleted,
        ];
        if (array_key_exists('cli', $validated)) {
            $update['cli'] = $validated['cli'];
        }
        if (array_key_exists('is_reportable', $validated)) {
            $update['is_reportable'] = (bool) $validated['is_reportable'];
        }
        $task->update($update);

        if (! $wasCompleted && $isCompleted) {
            $this->spawnRecurringInstance($task);
        }

        return back()->with('success', __('Task updated.'));
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorizeTask($task);

        if ($task->source === 'trello') {
            abort(422, 'Trello-source tasks must be deleted on the Trello board.');
        }

        $task->delete();

        return back()->with('success', __('Task deleted.'));
    }

    public function updateReportable(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        $validated = $request->validate([
            'is_reportable' => 'required|boolean',
        ]);

        $task->update(['is_reportable' => $validated['is_reportable']]);

        return response()->json(['is_reportable' => $task->is_reportable]);
    }

    public function updateArchived(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        if ($task->source === 'trello') {
            abort(422, 'Trello-source tasks must be archived on the Trello board.');
        }

        $validated = $request->validate([
            'archived' => 'required|boolean',
        ]);

        $task->update([
            'archived_at' => $validated['archived'] ? now() : null,
        ]);

        return response()->json([
            'archived_at' => $task->archived_at?->toIso8601String(),
        ]);
    }

    public function updateListName(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        if ($task->source === 'trello') {
            abort(422, 'Trello-source tasks must be moved on the Trello board. Try archiving on Trello instead.');
        }

        $validated = $request->validate([
            'list_name' => 'required|string|max:100',
        ]);

        $newListName = $validated['list_name'];
        $isCompleted = $newListName === 'Done';
        $wasCompleted = $task->is_completed;

        $task->update([
            'list_name' => $newListName,
            'is_completed' => $isCompleted,
        ]);

        if (! $wasCompleted && $isCompleted) {
            $this->spawnRecurringInstance($task);
        }

        return response()->json([
            'list_name' => $task->list_name,
            'is_completed' => $task->is_completed,
        ]);
    }

    public function updateCli(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        $validated = $request->validate([
            'cli' => ['nullable', Rule::in(Task::CLIS)],
        ]);

        $task->update(['cli' => $validated['cli']]);

        return response()->json(['cli' => $task->cli]);
    }

    public function updateAgentLane(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        if ($task->cli === null) {
            abort(422, 'Task is not on the agent kanban (no CLI configured).');
        }

        $validated = $request->validate([
            'agent_lane' => ['required', Rule::in(Task::AGENT_LANES)],
        ]);

        $update = ['agent_lane' => $validated['agent_lane']];

        $mappedListName = self::AGENT_LANE_TO_LIST_NAME[$validated['agent_lane']] ?? null;
        if ($mappedListName !== null) {
            $update['list_name'] = $mappedListName;
            $update['is_completed'] = $mappedListName === 'Done';
        }

        $wasCompleted = (bool) $task->is_completed;
        $task->update($update);

        // Completing via agent-kanban drag must spawn the next recurring
        // instance exactly like list-name completion does.
        if (! $wasCompleted && (bool) $task->is_completed) {
            $this->spawnRecurringInstance($task);
        }

        return response()->json([
            'agent_lane' => $task->agent_lane,
            'list_name' => $task->list_name,
            'is_completed' => $task->is_completed,
        ]);
    }

    /**
     * Render the task detail page. Slim payload — the page itself decides
     * what to display.
     */
    public function show(Task $task, \App\Services\TerminalSessionLauncherInterface $launcher)
    {
        $task->load([
            'project.client',
            'parentTask:id,name',
            'childTasks:id,parent_task_id,name,is_completed,agent_lane,cli',
            'attachments',
            'sessions.timeEntry:id,duration_minutes,start_time,end_time',
        ]);

        if ($task->project?->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        // Cumulative minutes across every terminal-session TimeEntry for this
        // task. Closed entries contribute duration_minutes directly; a running
        // entry's elapsed time is added live so the chip ticks without an
        // explicit refresh on stop.
        $sessionMinutes = (int) TimeEntry::query()
            ->where('task_id', $task->id)
            ->where('source', TimeEntry::SOURCE_TERMINAL_SESSION)
            ->whereNotNull('end_time')
            ->sum('duration_minutes');
        $runningEntry = TimeEntry::query()
            ->where('task_id', $task->id)
            ->where('source', TimeEntry::SOURCE_TERMINAL_SESSION)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();
        if ($runningEntry !== null && $runningEntry->start_time !== null) {
            $sessionMinutes += (int) ceil($runningEntry->start_time->diffInSeconds(now()) / 60);
        }

        // Manual stopwatch — separate from session-tracked time, surfaced as
        // a Start/Stop toggle in the title bar. Returns the open entry so the
        // UI can show elapsed seconds + the Stop button referring back to it.
        $manualRunning = TimeEntry::query()
            ->where('task_id', $task->id)
            ->where('source', TimeEntry::SOURCE_MANUAL)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        return Inertia::render('tasks/show', [
            'task' => [
                'id' => $task->id,
                'name' => $task->name,
                'description' => $task->description,
                'list_name' => $task->list_name,
                'is_completed' => (bool) $task->is_completed,
                'due_date' => $task->due_date?->toIso8601String(),
                'labels' => $task->labels,
                'priority' => $task->priority,
                'parent_task_id' => $task->parent_task_id,
                'parent_task' => $task->parentTask ? [
                    'id' => $task->parentTask->id,
                    'name' => $task->parentTask->name,
                ] : null,
                'child_tasks' => $task->childTasks->map(fn ($child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'is_completed' => (bool) $child->is_completed,
                    'agent_lane' => $child->agent_lane,
                    'cli' => $child->cli,
                ])->values(),
                'source' => $task->source,
                'trello_url' => $task->trello_url,
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
                'project' => $task->project ? [
                    'id' => $task->project->id,
                    'name' => $task->project->name,
                    'trello_url' => $task->project->trello_url,
                    // Preview config — drives the Start Preview button on the
                    // task page. preview_url is optional click-to-open hint.
                    'preview_command' => $task->project->preview_command,
                    'preview_working_dir' => $task->project->preview_working_dir,
                    'preview_url' => $task->project->preview_url,
                ] : null,
                'client' => $task->project?->client ? [
                    'id' => $task->project->client->id,
                    'name' => $task->project->client->name,
                ] : null,
                'agent_lane' => $task->agent_lane,
                'cli' => $task->cli,
                'session_port' => $task->session_port,
                'session_branch_name' => $task->cli !== null ? $task->sessionBranchName() : null,
                'session_attention_at' => $task->session_attention_at?->toIso8601String(),
                'session_total_minutes' => $sessionMinutes,
                // Number of distinct sessions (TaskSession rows) that contributed
                // to session_total_minutes. Shown alongside the total in the
                // task pill so the user sees "3 sessions · 19h 15m" instead of
                // a bare duration that looks suspicious on long-running tasks.
                'session_count' => $task->sessions->count(),
                // Whether the tmux session backing this task is still alive on
                // the host. Drives the Resume affordance: only show it when
                // reattach would actually succeed (otherwise only Start makes
                // sense). Cheap check — single `tmux has-session` shell-out.
                'tmux_alive' => $task->cli !== null ? $launcher->tmuxSessionAlive($task) : false,
                'running_manual_entry' => $manualRunning ? [
                    'id' => $manualRunning->id,
                    'start_time' => $manualRunning->start_time?->toIso8601String(),
                ] : null,
                'attachments' => $task->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'url' => route('task-attachments.show', $a->id),
                    'original_name' => $a->original_name,
                    'mime' => $a->mime,
                    'size' => $a->size,
                    'label' => $a->label,
                ])->values(),
                // Newest first via the relation's default order. ended_at
                // IS NULL marks the currently-live row, which matches
                // tasks.session_port when that is set.
                'sessions' => $task->sessions->map(function ($s): array {
                    $startedAt = $s->started_at;
                    $endedAt = $s->ended_at;
                    $durationMinutes = $endedAt !== null && $startedAt !== null
                        ? (int) ceil($startedAt->diffInSeconds($endedAt) / 60)
                        : null;

                    return [
                        'id' => $s->id,
                        'cli' => $s->cli,
                        'base_branch' => $s->base_branch,
                        'branch_name' => $s->branch_name,
                        'worktree_path' => $s->worktree_path,
                        'started_at' => $startedAt?->toIso8601String(),
                        'ended_at' => $endedAt?->toIso8601String(),
                        'ended_reason' => $s->ended_reason,
                        'duration_minutes' => $durationMinutes,
                        'is_running' => $endedAt === null,
                        'time_entry' => $s->timeEntry ? [
                            'id' => $s->timeEntry->id,
                            'title' => $s->timeEntry->title,
                            'task_id' => $s->timeEntry->task_id,
                            'duration_minutes' => $s->timeEntry->duration_minutes,
                            'start_time' => $s->timeEntry->start_time?->toIso8601String(),
                            'end_time' => $s->timeEntry->end_time?->toIso8601String(),
                            'description' => $s->timeEntry->description,
                            'billable' => (bool) $s->timeEntry->billable,
                            // Whether this entry is already mirrored to Clockify.
                            // UI uses this to hint that saves will push an update
                            // upstream — the actual push is server-side.
                            'pushed_to_clockify' => $s->timeEntry->clockify_entry_id !== null && $s->timeEntry->clockify_entry_id !== '',
                        ] : null,
                    ];
                })->values(),
                // Currently-running preview for this task, if any. Drives the
                // Start/Stop Preview button + the embedded preview ttyd pane.
                'preview' => (function () use ($task): ?array {
                    $running = $task->runningPreview();
                    if ($running === null) {
                        return null;
                    }

                    return [
                        'id' => $running->id,
                        'port' => $running->port,
                        'pid' => $running->pid,
                        'command' => $running->command,
                        'working_dir' => $running->working_dir,
                        'url' => $running->url,
                        'started_at' => $running->started_at?->toIso8601String(),
                    ];
                })(),
            ],
        ]);
    }

    /**
     * Recurrence lives on the Task model so every completion path (controller
     * or the crm:task-done CLI verb) spawns identically.
     */
    private function spawnRecurringInstance(Task $task): void
    {
        $task->spawnRecurringInstance();
    }

    private function authorizeTask(Task $task): void
    {
        $task->load('project');

        if ($task->project?->account_id !== Auth::user()->account_id) {
            abort(403);
        }
    }

    private function resolveParentTaskId(Project $project, ?int $parentTaskId, ?Task $task = null): ?int
    {
        if ($parentTaskId === null) {
            return null;
        }

        if ($task !== null && $task->id === $parentTaskId) {
            abort(422, 'A task cannot be its own parent.');
        }

        $parent = Task::where('project_id', $project->id)->where('id', $parentTaskId)->first();
        if (! $parent) {
            abort(422, 'Parent task must belong to the same project.');
        }

        if ($task !== null && $this->isDescendantTask($task, $parent)) {
            abort(422, 'A task cannot be moved under one of its subtasks.');
        }

        return $parent->id;
    }

    /**
     * Walks up the parent_task_id chain from $candidateParent looking for $task.
     * Returns true if a cycle would be created by setting task.parent_task_id = candidate.
     *
     * Guarded against pre-existing cycles in the DB (visited-set + depth cap) —
     * without those a malformed row hangs the worker until request timeout.
     */
    private function isDescendantTask(Task $task, Task $candidateParent): bool
    {
        $visited = [];
        $cursor = $candidateParent;
        $maxDepth = 64;

        while ($cursor !== null && $cursor->parent_task_id !== null) {
            $maxDepth--;
            if ($maxDepth <= 0) {
                return false;
            }

            $parentId = (int) $cursor->parent_task_id;
            if (isset($visited[$parentId])) {
                return false;
            }
            $visited[$parentId] = true;

            if ($parentId === (int) $task->id) {
                return true;
            }

            $cursor = Task::find($parentId);
        }

        return false;
    }
}

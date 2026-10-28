<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RefreshTaskCardDetailsJob;
use App\Models\Project;
use App\Models\Task;
use App\Services\Agent\CardDetailsFetcher;
use App\Services\Tasks\TaskActivity;
use App\Services\Tasks\TaskPage;
use App\Services\Tasks\TaskWriter;
use App\Services\Time\PeriodSettled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class TasksController extends Controller
{
    public function store(Request $request, Project $project, TaskWriter $writer): RedirectResponse
    {
        if ($project->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:500',
            'description' => 'nullable|string|max:50000',
            'list_name' => ['nullable', 'string', Rule::in(TaskWriter::LISTS)],
            'due_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
            'parent_task_id' => 'nullable|integer',
            'is_reportable' => 'nullable|boolean',
        ]);

        $writer->create($project, [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'list_name' => $validated['list_name'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'parent_task_id' => $this->resolveParentTaskId($project, $validated['parent_task_id'] ?? null),
            'is_reportable' => $validated['is_reportable'] ?? false,
        ]);

        return back()->with('success', __('Task created.'));
    }

    public function update(Request $request, Task $task, TaskWriter $writer): RedirectResponse
    {
        $this->authorizeTask($task);

        if ($task->hasTrelloCard()) {
            abort(422, 'Trello-source tasks must be edited on the Trello board.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:500',
            'description' => 'nullable|string|max:50000',
            'list_name' => ['nullable', 'string', Rule::in(TaskWriter::LISTS)],
            'due_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
            'parent_task_id' => 'nullable|integer',
            'is_reportable' => 'nullable|boolean',
        ]);

        $changes = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'parent_task_id' => array_key_exists('parent_task_id', $validated)
                ? $this->resolveParentTaskId($task->project, $validated['parent_task_id'] ?? null, $task)
                : $task->parent_task_id,
            'list_name' => $validated['list_name'] ?? $task->list_name,
        ];
        if (array_key_exists('is_reportable', $validated)) {
            $changes['is_reportable'] = (bool) $validated['is_reportable'];
        }
        if ($changes['list_name'] === null) {
            unset($changes['list_name']);
        }
        $writer->update($task, $changes);

        return back()->with('success', __('Task updated.'));
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->authorizeTask($task);

        if ($task->hasTrelloCard()) {
            abort(422, 'Trello-source tasks must be deleted on the Trello board.');
        }

        try {
            $task->delete();
        } catch (PeriodSettled $settled) {
            throw ValidationException::withMessages(['archive_instead' => $settled->deleteRefusal()]);
        }

        return response()->json(['deleted' => $task->id]);
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

    public function updateArchived(Request $request, Task $task, TaskWriter $writer): JsonResponse
    {
        $this->authorizeTask($task);

        if ($task->hasTrelloCard()) {
            abort(422, 'Trello-source tasks must be archived on the Trello board.');
        }

        $validated = $request->validate([
            'archived' => 'required|boolean',
        ]);

        $writer->setArchived($task, (bool) $validated['archived']);

        return response()->json([
            'archived_at' => $task->archived_at?->toIso8601String(),
        ]);
    }

    public function updateListName(Request $request, Task $task, TaskWriter $writer): JsonResponse
    {
        $this->authorizeTask($task);

        if ($task->hasTrelloCard()) {
            abort(422, 'Trello-source tasks must be moved on the Trello board. Try archiving on Trello instead.');
        }

        $validated = $request->validate([
            'list_name' => 'required|string|max:100',
        ]);

        $writer->moveToList($task, $validated['list_name']);

        return response()->json([
            'list_name' => $task->list_name,
            'is_completed' => $task->is_completed,
            'finished_at' => $task->finished_at?->toIso8601String(),
        ]);
    }

    /** The task page (Figma: Zadanie). A stale card's details are fetched behind the page, which shows the cache meanwhile. */
    public function show(Request $request, Task $task, TaskPage $page, CardDetailsFetcher $fetcher)
    {
        $this->authorizeTask($task);

        if ($task->hasTrelloCard() && ! config('app.demo') && $fetcher->isStale($task, $task->cardDetails)) {
            RefreshTaskCardDetailsJob::dispatch($task->id);
        }

        return Inertia::render('tasks/show', $page->props($task, $request->user(), $request->has('historia') ? null : TaskActivity::PREVIEW));
    }

    /** The description of a CRM task; a card's description is Trello's. */
    public function updateDescription(Request $request, Task $task, TaskWriter $writer): RedirectResponse
    {
        $this->authorizeTask($task);

        if ($task->hasTrelloCard()) {
            abort(422, 'Trello-source tasks must be edited on the Trello board.');
        }

        $validated = $request->validate([
            'description' => 'nullable|string|max:50000',
        ]);
        $description = mb_trim((string) ($validated['description'] ?? ''));

        $writer->update($task, ['description' => $description === '' ? null : $description]);

        return back();
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

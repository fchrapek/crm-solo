<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The per-project kanban. The client Work tab lists projects as one-line rows
 * and drills in here, so manual drag lives on a page of its own rather than
 * inside an accordion on a tab that also has to show everything else.
 */
final class ProjectBoardController extends Controller
{
    public function show(Client $client, Project $project): Response
    {
        if ($client->account_id !== Auth::user()->account_id || $project->client_id !== $client->id) {
            abort(404);
        }

        $tasks = $project->tasks()
            ->whereNull('archived_at')
            ->with(['parentTask:id,name'])
            ->withCount('childTasks')
            ->orderBy('position')
            ->get()
            ->map(fn (Task $task) => $this->mapTask($task));

        $settings = $project->settings ?? [];

        return Inertia::render('project-board/show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'is_private' => $project->trello_board_id === null,
                'trello_url' => $project->trello_url,
                'custom_lanes' => $settings['custom_lanes'] ?? [],
            ],
            'tasks' => $tasks,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'description' => $task->description,
            'list_name' => $task->list_name,
            'due_date' => $task->due_date?->toIso8601String(),
            'priority' => $task->priority,
            'ai_priority' => $task->ai_priority,
            'parent_task_id' => $task->parent_task_id,
            'parent_task' => $task->parentTask ? ['id' => $task->parentTask->id, 'name' => $task->parentTask->name] : null,
            'child_tasks_count' => (int) ($task->child_tasks_count ?? 0),
            'source' => $task->source,
            'type' => $task->type,
            'cli' => $task->cli,
            'is_completed' => (bool) $task->is_completed,
            'archived_at' => $task->archived_at?->toIso8601String(),
            'issue_url' => $task->issue_url,
            'trello_url' => $task->trello_url,
        ];
    }
}

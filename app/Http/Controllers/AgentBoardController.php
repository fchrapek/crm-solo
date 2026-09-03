<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AgentBoardController extends Controller
{
    public function show(Client $client, Project $project): Response
    {
        if ($client->account_id !== Auth::user()->account_id || $project->client_id !== $client->id) {
            abort(404);
        }

        $tasks = Task::onAgentBoard()
            ->where('project_id', $project->id)
            ->with(['parentTask:id,name'])
            ->withCount('childTasks')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn (Task $task) => $this->mapTask($task));

        return Inertia::render('agent-board/show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'tasks' => $tasks,
            'lanes' => Task::AGENT_LANES,
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
            'parent_task_id' => $task->parent_task_id,
            'parent_task' => $task->parentTask ? ['id' => $task->parentTask->id, 'name' => $task->parentTask->name] : null,
            'child_tasks_count' => (int) ($task->child_tasks_count ?? 0),
            'source' => $task->source,
            'type' => $task->type,
            'agent_lane' => $task->agent_lane ?? Task::AGENT_LANE_BACKLOG,
            'cli' => $task->cli,
            'session_branch_name' => $task->cli !== null ? $task->sessionBranchName() : null,
            'session_port' => $task->session_port,
            'session_attention_at' => $task->session_attention_at?->toIso8601String(),
            'issue_url' => $task->issue_url,
            'updated_at' => $task->updated_at?->toIso8601String(),
            'trello_url' => $task->trello_url,
        ];
    }
}

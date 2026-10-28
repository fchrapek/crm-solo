<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskBrief;
use App\Services\Agent\AgentIdentity;
use App\Services\Agent\TaskBriefWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The brief from the task page. The CRM owns the brief, on a Trello card
 * too, and the owner writing it confirms it, through the writer the agent
 * verbs use.
 */
final class TaskBriefController extends Controller
{
    public function update(Request $request, Task $task, TaskBriefWriter $writer): RedirectResponse
    {
        $user = $request->user();
        $task->loadMissing('project');
        abort_unless($task->project?->account_id === $user->account_id, 403);

        $rules = [];
        foreach (array_keys(TaskBrief::FIELDS) as $field) {
            $rules[$field] = ['nullable', 'string', 'max:5000'];
        }
        $validated = $request->validate($rules);

        $changes = [];
        foreach (array_keys(TaskBrief::FIELDS) as $field) {
            $changes[$field] = (string) ($validated[$field] ?? '');
        }

        $writer->write($task, $changes, true, new AgentIdentity($user->account, $user), TaskBrief::VIA_WEB);

        return back();
    }
}

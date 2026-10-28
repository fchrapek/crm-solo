<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Links under Zasoby. A link is the CRM's on a Trello card too; Trello never receives it. */
final class TaskLinksController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $task->loadMissing('project');
        abort_unless($task->project?->account_id === $request->user()->account_id, 403);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);
        $label = mb_trim((string) ($validated['label'] ?? ''));

        $task->links()->create([
            'url' => mb_trim($validated['url']),
            'label' => $label === '' ? null : $label,
        ]);

        return back();
    }

    public function destroy(Request $request, TaskLink $link): RedirectResponse
    {
        $link->loadMissing('task.project');
        abort_unless($link->task?->project?->account_id === $request->user()->account_id, 404);

        $link->delete();

        return back();
    }
}

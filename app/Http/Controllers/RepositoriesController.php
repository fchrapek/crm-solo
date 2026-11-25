<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RepositoriesController extends Controller
{
    /** A web address or a git remote; the page links the first and prints the second. */
    private const string REMOTE = 'regex:/\\A(https?:\\/\\/|ssh:\\/\\/|[\\w.-]+@[\\w.-]+:)\\S+\\z/i';

    public function store(Request $request, Project $project): RedirectResponse
    {
        if ($project->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'local_path' => 'nullable|string|max:500',
            'remote_url' => ['nullable', 'string', 'max:500', self::REMOTE],
            'provider' => 'nullable|string|in:github,gitlab,bitbucket,local',
        ]);

        Repository::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'local_path' => $validated['local_path'] ?? null,
            'remote_url' => $validated['remote_url'] ?? null,
            'provider' => $validated['provider'] ?? 'local',
        ]);

        return back()->with('success', __('Repository linked.'));
    }

    public function update(Request $request, Repository $repository): RedirectResponse
    {
        $repository->load('project');
        if ($repository->project->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'local_path' => 'nullable|string|max:500',
            'remote_url' => ['nullable', 'string', 'max:500', self::REMOTE],
            'provider' => 'nullable|string|in:github,gitlab,bitbucket,local',
        ]);

        $repository->update([
            'name' => $validated['name'],
            'local_path' => $validated['local_path'] ?? null,
            'remote_url' => $validated['remote_url'] ?? null,
            'provider' => $validated['provider'] ?? 'local',
        ]);

        return back()->with('success', __('Repository updated.'));
    }

    public function destroy(Repository $repository): RedirectResponse
    {
        $repository->load('project');
        if ($repository->project->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        $repository->delete();

        return back()->with('success', __('Repository unlinked.'));
    }
}

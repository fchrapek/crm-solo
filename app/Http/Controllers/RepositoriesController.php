<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

final class RepositoriesController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        if ($project->account_id !== Auth::user()->account_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'local_path' => 'nullable|string|max:500',
            'remote_url' => 'nullable|string|max:500',
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
            'remote_url' => 'nullable|string|max:500',
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

    /**
     * List local branches in the repository, sorted by most-recent commit.
     * Used by <BranchPicker> in the agent dialogs to let the user pick which
     * branch the agent should cut its working branch from. Cached briefly so
     * dialog opens don't hammer git on every keystroke.
     */
    public function branches(Repository $repository): JsonResponse
    {
        $repository->load('project');
        if ($repository->project->account_id !== Auth::user()->account_id) {
            abort(403);
        }
        if ($repository->local_path === null || ! is_dir($repository->local_path.'/.git')) {
            return response()->json(['branches' => [], 'current' => null]);
        }

        $cacheKey = "repo-branches:{$repository->id}";
        $payload = Cache::remember($cacheKey, 30, function () use ($repository) {
            $proc = new Process([
                'git', '-C', $repository->local_path,
                'for-each-ref',
                '--sort=-committerdate',
                'refs/heads/',
                '--format=%(refname:short)|%(committerdate:relative)',
                '--count=50',
            ]);
            $proc->setTimeout(5);
            $proc->run();
            if (! $proc->isSuccessful()) {
                return ['branches' => [], 'current' => null, 'error' => mb_trim($proc->getErrorOutput())];
            }

            $branches = [];
            foreach (explode("\n", mb_trim($proc->getOutput())) as $line) {
                if ($line === '') {
                    continue;
                }
                [$name, $age] = array_pad(explode('|', $line, 2), 2, '');
                $branches[] = ['name' => $name, 'age' => $age];
            }

            $current = new Process(['git', '-C', $repository->local_path, 'rev-parse', '--abbrev-ref', 'HEAD']);
            $current->setTimeout(5);
            $current->run();

            return [
                'branches' => $branches,
                'current' => $current->isSuccessful() ? mb_trim($current->getOutput()) : null,
            ];
        });

        return response()->json($payload);
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

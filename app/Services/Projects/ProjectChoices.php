<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;

/**
 * The projects a new task can go to: every live project of the account (not archived, its client not deleted),
 * sorted by client then name, and the few worked on last, first time logged and then tasks changed.
 */
final class ProjectChoices
{
    public const int RECENT = 3;

    /**
     * @return array{recent: list<int>, projects: list<array{id: int, name: string, client_id: ?int, client: ?string}>}
     */
    public function forAccount(int $accountId): array
    {
        $projects = Project::query()
            ->where('account_id', $accountId)
            ->notArchived()
            ->where('is_inbox', false)
            ->where(fn ($q) => $q->whereNull('client_id')->orWhereHas('client'))
            ->with('client')
            ->get()
            ->sortBy([
                fn (Project $a, Project $b) => [$a->client === null, mb_strtolower((string) $a->client?->name)] <=> [$b->client === null, mb_strtolower((string) $b->client?->name)],
                fn (Project $a, Project $b) => strnatcasecmp($a->name, $b->name),
            ])
            ->values();
        $live = $projects->pluck('id')->flip();

        $recent = $this->workedOn($accountId)
            ->merge($this->changed($accountId))
            ->unique()
            ->filter(fn (int $id) => $live->has($id))
            ->take(self::RECENT)
            ->values()
            ->all();

        return [
            'recent' => $recent,
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'client_id' => $project->client_id,
                'client' => $project->client?->name,
            ])->all(),
        ];
    }

    /** @return Collection<int, int> */
    private function workedOn(int $accountId): Collection
    {
        return TimeEntry::query()
            ->where('account_id', $accountId)
            ->whereNotNull('project_id')
            ->groupBy('project_id')
            ->orderByRaw('MAX(start_time) DESC')
            ->limit(self::RECENT * 3)
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id);
    }

    /** @return Collection<int, int> */
    private function changed(int $accountId): Collection
    {
        return Task::query()
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->where('projects.account_id', $accountId)
            ->groupBy('tasks.project_id')
            ->orderByRaw('MAX(tasks.updated_at) DESC')
            ->limit(self::RECENT * 3)
            ->pluck('tasks.project_id')
            ->map(fn ($id) => (int) $id);
    }
}

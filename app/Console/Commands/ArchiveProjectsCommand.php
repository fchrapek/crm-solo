<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProjectAndClient;
use App\Models\Project;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Dismiss a project without losing it.
 *
 * The case this exists for: a Trello board that got imported and is not work
 * you track here. Deleting it drops the task history AND lets the next sync
 * recreate the project, so the only move that sticks is a tombstone.
 */
final class ArchiveProjectsCommand extends Command
{
    use ResolvesProjectAndClient;

    protected $signature = 'projects:archive
                            {project?* : Project IDs or names (repeatable)}
                            {--orphans : Target every project with no client instead}
                            {--restore : Un-archive instead}
                            {--account= : Restrict resolution to this account ID}';

    protected $description = 'Archive projects (hidden from views, kept in the DB, skipped by Trello sync). --restore reverses it.';

    public function handle(): int
    {
        $accountId = $this->intOption('account');
        $restoring = (bool) $this->option('restore');

        try {
            $projects = $this->targets($accountId, $restoring);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($projects->isEmpty()) {
            $this->warn('Nothing to '.($restoring ? 'restore' : 'archive').'.');

            return self::SUCCESS;
        }

        $verb = $restoring ? 'Restoring' : 'Archiving';
        $this->line("{$verb} ".$projects->count().' projects…');

        foreach ($projects as $project) {
            $project->update(['archived_at' => $restoring ? null : now()]);
            $tasks = $project->tasks()->count();
            $this->line("  ✓ #{$project->id} {$project->name} ({$tasks} tasks kept)");
        }

        $this->info('Done.');

        if (! $restoring) {
            $this->line('Trello sync will now skip these boards. Undo with --restore.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Project> */
    private function targets(?int $accountId, bool $restoring): \Illuminate\Support\Collection
    {
        $needles = (array) $this->argument('project');

        if ($this->option('orphans')) {
            if ($needles !== []) {
                throw new RuntimeException('Pass either --orphans or explicit projects, not both.');
            }

            $query = Project::query()->whereNull('client_id');

            return ($restoring ? $query->archived() : $query->notArchived())
                ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
                ->orderBy('id')
                ->get();
        }

        if ($needles === []) {
            throw new RuntimeException('Name at least one project, or pass --orphans.');
        }

        return collect($needles)
            ->map(fn (string $needle): Project => $this->resolveProject($needle, $accountId))
            ->unique('id')
            ->values();
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}

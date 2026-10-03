<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Console\Commands\Concerns\ResolvesProjectAndClient;
use App\Models\Task;
use Illuminate\Console\Command;
use RuntimeException;

#[AccountScope(AccountScope::ACTING)]
final class DeleteTasksCommand extends Command
{
    use AgentConsoleOutput;
    use ResolvesProjectAndClient;

    protected $signature = 'tasks:delete
                            {--project= : Project ID or name (required)}
                            {--source= : Only delete tasks with this source (manual|email|trello|planner)}
                            {--list= : Only delete tasks in this lane (Backlog|To-Do|Doing|Testing|Done)}
                            {--archived : Only delete already-archived tasks}
                            {--archive-only : Soft-archive (set archived_at) instead of deleting}
                            {--force : Skip confirmation}
                            {--account= : Account ID; must be the acting account}';

    protected $description = 'Mass-delete (or archive) tasks on a project. Without filters, targets ALL tasks on the project.';

    public function handle(): int
    {
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        $projectNeedle = (string) ($this->option('project') ?? '');
        if ($projectNeedle === '') {
            $this->error('Missing --project (id or name).');

            return self::INVALID;
        }

        try {
            $project = $this->resolveProject($projectNeedle, $accountId);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $query = Task::where('project_id', $project->id);
        $filters = [];

        if ($source = $this->option('source')) {
            if (! in_array($source, [Task::SOURCE_MANUAL, Task::SOURCE_EMAIL, Task::SOURCE_TRELLO], true)) {
                $this->error("Invalid --source \"{$source}\".");

                return self::INVALID;
            }
            $query->where('source', $source);
            $filters[] = "source={$source}";
        }
        if ($list = $this->option('list')) {
            $query->where('list_name', $list);
            $filters[] = "list={$list}";
        }
        if ($this->option('archived')) {
            $query->whereNotNull('archived_at');
            $filters[] = 'archived';
        }

        $count = $query->count();
        if ($count === 0) {
            $this->info('No matching tasks. Nothing to do.');

            return self::SUCCESS;
        }

        $action = $this->option('archive-only') ? 'archive' : 'DELETE';
        $filterDesc = $filters === [] ? 'ALL tasks (no filters)' : implode(', ', $filters);
        $summary = "{$action} {$count} tasks on project #{$project->id} \"{$project->name}\" — filter: {$filterDesc}";

        if (! $this->option('force') && ! $this->confirm($summary.'. Proceed?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        if ($this->option('archive-only')) {
            $affected = $query->whereNull('archived_at')->update(['archived_at' => now()]);
            $this->info("Archived {$affected} tasks.");
        } else {
            // One by one so Task::deleting runs: subtasks and time entries are detached, attachment files removed.
            $affected = $query->get()->each(fn (Task $task) => $task->delete())->count();
            $this->info("Deleted {$affected} tasks. Their time entries stay, detached from the task.");
        }

        return self::SUCCESS;
    }
}

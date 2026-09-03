<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProjectAndClient;
use App\Models\Task;
use Illuminate\Console\Command;
use RuntimeException;

final class CreateTasksCommand extends Command
{
    use ResolvesProjectAndClient;

    protected $signature = 'tasks:create
                            {--project= : Project ID or name (required)}
                            {--name=* : Task name (repeatable — one task per --name)}
                            {--count= : Bulk-create N tasks using --name as a prefix + numeric suffix}
                            {--list=Backlog : Canonical lane (Backlog|To-Do|Doing|Testing|Done)}
                            {--priority= : low|medium|high}
                            {--source=manual : manual|email|trello|planner}
                            {--description= : Optional description applied to every task}
                            {--account= : Restrict resolution to this account ID}';

    protected $description = 'Mass-create tasks on a project. Use --name multiple times for distinct tasks, or --count for N copies.';

    public function handle(): int
    {
        $projectNeedle = (string) ($this->option('project') ?? '');
        if ($projectNeedle === '') {
            $this->error('Missing --project (id or name).');

            return self::INVALID;
        }

        try {
            $project = $this->resolveProject($projectNeedle, $this->intOption('account'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $names = (array) $this->option('name');
        $count = $this->intOption('count');
        $list = (string) $this->option('list');
        $source = (string) $this->option('source');
        $priority = $this->option('priority');
        $description = $this->option('description');

        if (! in_array($list, ['Backlog', 'To-Do', 'Doing', 'Testing', 'Done'], true)) {
            $this->error("Invalid --list \"{$list}\". Use one of: Backlog, To-Do, Doing, Testing, Done.");

            return self::INVALID;
        }
        if (! in_array($source, [Task::SOURCE_MANUAL, Task::SOURCE_EMAIL, Task::SOURCE_TRELLO], true)) {
            $this->error("Invalid --source \"{$source}\". Use manual|email|trello.");

            return self::INVALID;
        }
        if ($priority !== null && ! in_array($priority, ['low', 'medium', 'high'], true)) {
            $this->error("Invalid --priority \"{$priority}\". Use low|medium|high.");

            return self::INVALID;
        }
        if ($names === []) {
            $this->error('Pass at least one --name="…".');

            return self::INVALID;
        }
        if ($count !== null && count($names) > 1) {
            $this->error('--count works with a single --name (used as prefix). Drop one or the other.');

            return self::INVALID;
        }

        $finalNames = $count !== null
            ? array_map(fn (int $i) => "{$names[0]} #{$i}", range(1, $count))
            : $names;

        $payload = [
            'project_id' => $project->id,
            'source' => $source,
            'list_name' => $list,
            'is_reviewed' => true,
            'priority' => $priority,
            'description' => $description,
        ];

        $this->line('Creating '.count($finalNames)." tasks on project #{$project->id} \"{$project->name}\"…");

        foreach ($finalNames as $name) {
            $task = Task::create(['name' => $name] + $payload);
            $this->line("  ✓ #{$task->id} {$name}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}

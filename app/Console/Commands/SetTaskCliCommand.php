<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;

final class SetTaskCliCommand extends Command
{
    protected $signature = 'tasks:cli {task : Task ID} {cli? : claude | codex | null (clears)}';

    protected $description = 'Set the terminal-session CLI on a task (claude/codex/null).';

    public function handle(): int
    {
        $task = Task::find((int) $this->argument('task'));
        if ($task === null) {
            $this->error('Task not found.');

            return self::FAILURE;
        }

        $cli = $this->argument('cli');
        if ($cli === 'null' || $cli === '') {
            $cli = null;
        }

        if ($cli !== null && ! in_array($cli, Task::CLIS, true)) {
            $this->error('Invalid cli. Use one of: '.implode(', ', Task::CLIS).' (or null to clear).');

            return self::FAILURE;
        }

        $task->update(['cli' => $cli]);

        $this->info(sprintf('Task #%d cli set to %s.', $task->id, $cli ?? 'null'));

        return self::SUCCESS;
    }
}

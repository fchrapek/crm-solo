<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Task;
use App\Services\Agent\ReferenceNotFoundException;
use Illuminate\Console\Command;

#[AccountScope(AccountScope::ACTING)]
final class SetTaskCliCommand extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'tasks:cli {task : Task ID} {cli? : claude | codex | null (clears)}';

    protected $description = 'Set the terminal-session CLI on a task (claude/codex/null).';

    public function handle(): int
    {
        $id = (string) $this->argument('task');
        $task = ctype_digit($id)
            ? Task::query()->whereHas('project', fn ($q) => $q->where('account_id', $this->actingIdentity()->account->id))->find((int) $id)
            : null;
        if ($task === null) {
            return $this->referenceFailure(new ReferenceNotFoundException('task', $id));
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

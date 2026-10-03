<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Console\Commands\Concerns\ResolvesProjectAndClient;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[AccountScope(AccountScope::ACTING)]
final class DeleteProjectsCommand extends Command
{
    use AgentConsoleOutput;
    use ResolvesProjectAndClient;

    protected $signature = 'projects:delete
                            {project? : Project ID or name (single-project mode)}
                            {--client= : Client ID or name — wipe ALL projects for this client (except General)}
                            {--include-general : When using --client, also delete the "General" project}
                            {--force : Skip confirmation}
                            {--account= : Account ID; must be the acting account}';

    protected $description = 'Delete a single project OR wipe all projects for a client, with their tasks. Time entries stay, detached from the deleted tasks.';

    public function handle(): int
    {
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        $projectArg = $this->argument('project');
        $clientNeedle = $this->option('client');

        if ($projectArg && $clientNeedle) {
            $this->error('Pass either a project argument OR --client, not both.');

            return self::INVALID;
        }
        if (! $projectArg && ! $clientNeedle) {
            $this->error('Pass a project ID/name as argument, or --client to wipe all projects for a client.');

            return self::INVALID;
        }

        try {
            $projects = $projectArg
                ? collect([$this->resolveProject((string) $projectArg, $accountId)])
                : $this->projectsForClient((string) $clientNeedle, $accountId, (bool) $this->option('include-general'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($projects->isEmpty()) {
            $this->info('No matching projects. Nothing to do.');

            return self::SUCCESS;
        }

        $taskTotal = Task::whereIn('project_id', $projects->pluck('id'))->count();
        $trelloLinked = $projects->whereNotNull('trello_board_id');

        $this->line("Targeting {$projects->count()} project(s), cascading {$taskTotal} task(s):");
        foreach ($projects as $p) {
            $extra = [];
            if ($p->trello_board_id) {
                $extra[] = 'Trello-linked';
            }
            if ($p->is_inbox) {
                $extra[] = 'INBOX';
            }
            $tag = $extra === [] ? '' : ' ['.implode(', ', $extra).']';
            $this->line("  #{$p->id} \"{$p->name}\"{$tag}");
        }

        if ($trelloLinked->isNotEmpty()) {
            $this->warn('⚠ Trello-linked projects will be removed from the CRM only — Trello boards remain. Consider disconnecting via UI instead.');
        }
        if ($projects->contains('is_inbox', true)) {
            $this->warn('⚠ An Inbox project is in the target set — email routing falls back to lazy-create, so this is recoverable.');
        }

        if (! $this->option('force') && ! $this->confirm('Proceed with delete?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // Project::deleting deletes each task through its model, which detaches time entries and removes attachment files.
        DB::transaction(fn () => $projects->each(fn (Project $p) => $p->delete()));

        $this->info("Deleted {$projects->count()} project(s) and {$taskTotal} task(s). Their logged time stays, billed to the client without a task.");

        return self::SUCCESS;
    }

    private function projectsForClient(string $needle, int $accountId, bool $includeGeneral)
    {
        $client = $this->resolveClient($needle, $accountId);

        $query = Project::where('client_id', $client->id);
        if (! $includeGeneral) {
            $query->where('name', '!=', 'General');
        }

        return $query->get();
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Client;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Console\Command;

/**
 * Report drift between the projects flagged as month-close sites and what the
 * disk says: projects that look like sites but are not flagged, and flagged
 * sites whose local repo has moved or gone.
 *
 * A repository makes a project a *candidate*, not a site — a shared theme repo
 * is nobody's backup target, and a site can be archived from live with no local
 * copy at all. So this proposes; --apply writes.
 */
#[AccountScope(AccountScope::ACTING)]
final class MonthCloseSyncSites extends Command
{
    use AgentConsoleOutput;

    protected $signature = 'month-close:sync-sites
                            {--apply : Write the proposed flags (default is a proposal only)}
                            {--prune : Also clear the flag on in-close projects that have neither a repository nor a backup folder}';

    protected $description = 'Report month-close site drift: projects that look like sites but are not flagged, and flagged sites whose repo has gone missing. Writes with --apply.';

    public function handle(): int
    {
        // Proposal by default: re-flagging a candidate on every run would
        // undo a deliberate call, so the decision stays with the edit dialog.
        $write = (bool) $this->option('apply');
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        $clientIds = Client::query()
            ->where('account_id', $accountId)
            ->whereNotNull('month_close_type')
            ->where('include_in_month_close', true)
            ->pluck('id');

        if ($clientIds->isEmpty()) {
            $this->warn('No clients are in the monthly close.');

            return self::SUCCESS;
        }

        $projects = Project::query()
            ->whereIn('client_id', $clientIds)
            ->with('client:id,name')
            ->orderBy('client_id')
            ->orderBy('name')
            ->get();

        $repos = Repository::whereIn('project_id', $projects->pluck('id'))->get()->groupBy('project_id');

        $flagged = 0;
        $cleared = 0;
        $rows = [];

        foreach ($projects as $project) {
            $repo = $repos->get($project->id)?->first();
            $shouldBeSite = $repo !== null;
            $isSite = (bool) $project->include_in_month_close;

            $note = $this->repoNote($repo?->local_path);

            if ($shouldBeSite && ! $isSite) {
                $write && $project->update(['include_in_month_close' => true]);
                $flagged++;
                $rows[] = [$project->client?->name, $project->name, '+ site', $note];

                continue;
            }

            // A site with a backup folder but no repository is still a site:
            // some sites are archived straight from live with no local copy.
            // Only a project with neither is safe to clear.
            $hasBackup = $project->backup_path !== null && $project->backup_path !== '';

            if (! $shouldBeSite && ! $hasBackup && $isSite && $this->option('prune')) {
                $write && $project->update(['include_in_month_close' => false]);
                $cleared++;
                $rows[] = [$project->client?->name, $project->name, '- site (no repo)', $note];

                continue;
            }

            // Already correct, but still worth surfacing a repo that has gone
            // missing on disk — the run would fail at the first ddev call.
            if ($isSite && $note !== 'ok') {
                $rows[] = [$project->client?->name, $project->name, 'site', $note];
            }
        }

        if ($rows !== []) {
            $this->table(['client', 'project', 'change', 'repo'], $rows);
        }

        $this->line(sprintf(
            '%s%d flagged, %d cleared, %d sites in the close.',
            $write ? '' : 'PROPOSAL ONLY (pass --apply). ',
            $flagged,
            $cleared,
            Project::whereIn('client_id', $clientIds)->where('include_in_month_close', true)->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Whether the repo path is something a run could actually work in.
     */
    private function repoNote(?string $path): string
    {
        if ($path === null || $path === '') {
            return 'no repository';
        }

        if (! is_dir($path)) {
            return "MISSING: {$path}";
        }

        if (! is_dir(mb_rtrim($path, '/').'/.git')) {
            return "NOT A GIT REPO: {$path}";
        }

        return 'ok';
    }
}

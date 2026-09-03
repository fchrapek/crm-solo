<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Client;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Support\Carbon;

/**
 * A client's month-close config (cohort, ssh, backup path, per-site repos,
 * invoicing) — the per-client inputs the month-close-site skill needs.
 */
final class ClientConfigData
{
    /**
     * @return array<string, mixed>
     */
    public function for(Client $client): array
    {
        $retainer = $client->activeRetainerOn(Carbon::now());

        return [
            'id' => $client->id,
            'name' => $client->name,
            'month_close_type' => $client->month_close_type,
            'report_mode' => $client->report_mode,
            'ssh_config' => $client->ssh_config,
            'sites' => $this->sites($client),
            'maintenance_invoice_description' => $client->maintenance_invoice_description,
            'infakt_client_id' => $client->external_ids['infakt'] ?? null,
            'retainer' => $retainer !== null ? [
                'monthly_fee' => $retainer->monthly_fee !== null ? (float) $retainer->monthly_fee : null,
                'currency' => $retainer->currency,
            ] : null,
        ];
    }

    /**
     * Every site in the client's close, with its local repo. Clients here run
     * two or three sites, so a single "repo_path" would silently pick one of
     * them and send the run at the wrong tree.
     *
     * @return list<array<string, mixed>>
     */
    private function sites(Client $client): array
    {
        $projects = $client->monthCloseSites()->get();
        $repos = Repository::whereIn('project_id', $projects->pluck('id'))->get()->keyBy('project_id');

        return $projects->map(function (Project $project) use ($repos): array {
            $repo = $repos->get($project->id);
            $path = $repo?->local_path;

            return [
                'project_id' => (int) $project->id,
                'project' => $project->name,
                // Where this site's dumps are filed. The client's backup_path is
                // only the vault base; the run writes into this folder's
                // {YYYY}/{YYYYMMDD} tree.
                'backup_path' => $project->backup_path,
                'repo_path' => $path,
                'repo_remote' => $repo?->remote_url,
                'ddev_project' => $this->ddevProject($path),
            ];
        })->values()->all();
    }

    /**
     * The DDEV project name, read from the project's own config rather than
     * derived from the folder name. The two disagree wherever a repo folder
     * carries the domain but the DDEV project drops it, and a wrong name
     * sends every `ddev` call at nothing.
     */
    private function ddevProject(?string $repoPath): ?string
    {
        if ($repoPath === null || $repoPath === '') {
            return null;
        }

        $config = mb_rtrim($repoPath, '/').'/.ddev/config.yaml';

        if (is_readable($config) && preg_match('/^name:\s*["\']?([^"\'\s]+)/m', (string) file_get_contents($config), $matches) === 1) {
            return $matches[1];
        }

        return basename($repoPath);
    }
}

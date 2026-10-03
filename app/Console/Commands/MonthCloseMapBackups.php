<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Console\Commands\Concerns\AgentConsoleOutput;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Propose each month-close site's backup folder by looking at the vault, and
 * write the ones it is sure about.
 *
 * The vault does not name folders after CRM projects and does not nest them at
 * a constant depth, so this resolves what it can and reports the rest for a
 * human to fill in. It never invents a folder: every proposal is a directory
 * that exists on disk right now.
 */
#[AccountScope(AccountScope::ACTING)]
final class MonthCloseMapBackups extends Command
{
    use AgentConsoleOutput;

    /**
     * Trailing path segments kept by short(); enough to identify a site.
     */
    private const SHORT_PATH_SEGMENTS = 3;

    protected $signature = 'month-close:map-backups
                            {--apply : Write the resolved paths (default is a proposal only)}
                            {--force : Also overwrite sites that already have a backup path}
                            {--base= : Vault folder to search, for a client whose sites are all unmapped}';

    protected $description = 'Resolve each month-close site\'s backup folder against the vault and report or apply the mapping.';

    public function handle(): int
    {
        $accountId = $this->actingAccountId();
        if ($accountId === null) {
            return self::FAILURE;
        }

        $clients = Client::query()
            ->where('account_id', $accountId)
            ->where('include_in_month_close', true)
            ->with('monthCloseSites')
            ->orderBy('name')
            ->get();

        $rows = [];
        $applied = 0;
        $unresolved = 0;

        foreach ($clients as $client) {
            $base = $this->baseFor($client);

            if ($base === null) {
                foreach ($client->monthCloseSites as $site) {
                    $rows[] = [$client->name, $site->name, '—', $site->backup_path !== null ? 'kept' : 'no base — pass --base'];
                    $unresolved += $site->backup_path !== null ? 0 : 1;
                }

                continue;
            }

            $pending = $client->monthCloseSites
                ->reject(fn (Project $site): bool => $site->backup_path !== null && $site->backup_path !== '' && ! $this->option('force'));

            foreach ($client->monthCloseSites->diff($pending) as $site) {
                $rows[] = [$client->name, $site->name, $this->short((string) $site->backup_path), is_dir((string) $site->backup_path) ? 'kept' : 'kept (MISSING)'];
            }

            $resolved = $this->resolveClient($base, $pending, $client->monthCloseSites->count());

            foreach ($pending as $site) {
                $hit = $resolved[$site->id] ?? null;

                if ($hit === null) {
                    $rows[] = [$client->name, $site->name, '—', 'NEEDS MANUAL'];
                    $unresolved++;

                    continue;
                }

                [$path, $how] = $hit;

                if ($this->option('apply')) {
                    $site->update(['backup_path' => $path]);
                    $applied++;
                }

                $rows[] = [$client->name, $site->name, $this->short($path), $how];
            }
        }

        $this->table(['client', 'site', 'backup folder', 'how'], $rows);
        $this->line(sprintf(
            '%s%d resolved, %d need manual entry.',
            $this->option('apply') ? "Applied {$applied}. " : 'PROPOSAL ONLY (pass --apply). ',
            count($rows) - $unresolved,
            $unresolved,
        ));

        return self::SUCCESS;
    }

    /**
     * The vault folder to search for this client's sites.
     *
     * Derived from the sites already mapped rather than stored on the client: a
     * stored base is a second source of truth that goes stale the moment a vault
     * folder is renamed, while the sites' own paths cannot — they are what the
     * run actually writes to. A client with nothing mapped yet needs --base once.
     */
    private function baseFor(Client $client): ?string
    {
        $mapped = $client->monthCloseSites
            ->pluck('backup_path')
            ->filter(fn (?string $p): bool => $p !== null && $p !== '')
            ->values();

        if ($mapped->isEmpty()) {
            $given = $this->option('base');

            return is_string($given) && is_dir($given) ? mb_rtrim($given, '/') : null;
        }

        // Walk up from a mapped site until the folder holds every mapped path —
        // that is the level the sites branch from.
        $base = dirname((string) $mapped->first());

        while ($base !== '/' && $base !== '.') {
            $prefix = $base.'/';

            if ($mapped->every(fn (string $p): bool => str_starts_with($p, $prefix))) {
                return is_dir($base) ? $base : null;
            }

            $base = dirname($base);
        }

        return null;
    }

    /**
     * Resolve a client's sites together, claiming folders as they are matched.
     *
     * Resolving one site at a time is unsafe here: when one site name is a
     * prefix of another ("Acme" and "Acme - Landing Page"), both fuzzy-match
     * the main site's folder, so a per-site pass hands the landing page the
     * main site's backups.
     * Matching in order of decreasing confidence and removing each folder as it
     * is taken keeps a weaker match from stealing a stronger one's folder, and
     * anything still contended is left for a human.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $sites
     * @return array<int, array{0: string, 1: string}> keyed by project id
     */
    private function resolveClient(string $base, $sites, int $totalSites): array
    {
        $base = mb_rtrim($base, '/');
        $all = array_values(array_filter(
            scandir($base) ?: [],
            fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($base.'/'.$entry),
        ));

        // Only folders that actually hold backups can be a destination. Without
        // this, a name match happily lands on a sibling holding video assets or
        // per-location design files.
        $folders = array_values(array_filter($all, fn (string $f): bool => $this->holdsBackups($base.'/'.$f)));

        // A vault that keeps the dated tree directly under the base has no
        // per-site level to match against at all.
        if ($this->holdsYears($all)) {
            return $sites->mapWithKeys(fn (Project $s): array => [$s->id => [$base, 'years under base']])->all();
        }

        $out = [];
        $pending = $sites->all();

        // Tier 1 exact name, tier 2 normalized equality, tier 3 a token unique to
        // one folder, tier 4 plain containment. Each tier only sees folders no
        // earlier tier claimed.
        foreach (['exact name', 'normalized', 'distinctive token', 'name match'] as $tier) {
            $claimedThisTier = [];

            foreach ($pending as $key => $site) {
                $hits = array_values(array_filter(
                    $folders,
                    fn (string $folder): bool => $this->matches($tier, $site->name, $folder, $folders),
                ));

                if (count($hits) !== 1) {
                    continue;
                }

                // Another still-unresolved site wanting the same folder means we
                // cannot tell them apart — leave both to a human.
                $contended = collect($pending)
                    ->reject(fn (Project $other): bool => $other->id === $site->id)
                    ->contains(fn (Project $other): bool => $this->matches($tier, $other->name, $hits[0], $folders));

                if ($contended) {
                    continue;
                }

                $out[$site->id] = [$this->withBackupDir($base.'/'.$hits[0]), $tier];
                $claimedThisTier[] = $hits[0];
                unset($pending[$key]);
            }

            $folders = array_values(array_diff($folders, $claimedThisTier));
        }

        // One site, one shared backup folder: the vault never grew a per-site level.
        $shared = $this->sharedBackupDir($folders);

        foreach ($pending as $site) {
            if ($totalSites === 1 && $shared !== null) {
                $out[$site->id] = [$base.'/'.$shared, 'single site'];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $siblings
     */
    private function matches(string $tier, string $siteName, string $folder, array $siblings): bool
    {
        $needle = $this->normalize($siteName);
        $candidate = $this->normalize($folder);

        if ($candidate === '' || $needle === '') {
            return false;
        }

        return match ($tier) {
            'exact name' => mb_strtolower($folder) === mb_strtolower($siteName),
            'normalized' => $candidate === $needle,
            // A token this folder has and no sibling folder does. In a set like
            // shop.acme and acme, "shop" tells them apart and "acme" does not,
            // so the shared token is ignored.
            'distinctive token' => (bool) collect($this->tokens($folder))
                ->reject(fn (string $token): bool => collect($siblings)
                    ->reject(fn (string $other): bool => $other === $folder)
                    ->contains(fn (string $other): bool => in_array($token, $this->tokens($other), true)))
                ->first(fn (string $token): bool => str_contains($needle, $token)),
            default => str_contains($needle, $candidate) || str_contains($candidate, $needle),
        };
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($value)) ?: [],
            fn (string $token): bool => mb_strlen($token) >= 4,
        ));
    }

    /**
     * Descend into 01_BACKUP when the site folder has one; otherwise the site
     * folder is itself the dated root.
     */
    private function withBackupDir(string $path): string
    {
        $entries = array_values(array_filter(
            scandir($path) ?: [],
            fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path.'/'.$entry),
        ));

        $shared = $this->sharedBackupDir($entries);

        return $shared !== null ? $path.'/'.$shared : $path;
    }

    /**
     * The folder holding this level's dated tree. Most of the vault spells it
     * 01_BACKUP; one client spells it backup, so match on the name rather than
     * on one literal.
     *
     * @param  list<string>  $folders
     */
    private function sharedBackupDir(array $folders): ?string
    {
        foreach ($folders as $folder) {
            if (in_array(mb_strtolower($folder), ['01_backup', 'backup'], true)) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Whether this folder is a plausible dump destination: it either holds the
     * dated tree itself, or holds an 01_BACKUP that does.
     */
    private function holdsBackups(string $path): bool
    {
        if (basename($path) === '01_BACKUP') {
            return true;
        }

        if (is_dir($path.'/01_BACKUP')) {
            return true;
        }

        $entries = array_values(array_filter(
            scandir($path) ?: [],
            fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path.'/'.$entry),
        ));

        return $this->holdsYears($entries);
    }

    /**
     * @param  list<string>  $folders
     */
    private function holdsYears(array $folders): bool
    {
        foreach ($folders as $folder) {
            if (preg_match('/^(19|20)\d{2}$/', $folder) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare on letters and digits only, without the domain suffix: a vault
     * folder may drop the TLD that the project name carries, or carry one the
     * project name drops.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/\.(pl|com|de|eu|net|org|academy|studio)\b/', '', $value);

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }

    /**
     * Keep the table readable by showing only the tail of a path. Archive
     * roots are long and identical on every row, so the leading segments
     * carry no information; the last few are what tells two sites apart.
     */
    private function short(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path), fn (string $p): bool => $p !== ''));

        return count($parts) <= self::SHORT_PATH_SEGMENTS
            ? $path
            : '…/'.implode('/', array_slice($parts, -self::SHORT_PATH_SEGMENTS));
    }
}

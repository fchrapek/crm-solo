<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Builds a shell command prefix that strips the parent process's environment
 * before spawning a detached child (ttyd, tmux, …).
 *
 * Why: the CRM's PHP server loads `.env` and exports DB/REDIS/MAIL/OAUTH/etc.
 * vars into its own environment. Child processes spawned via `nohup …` inherit
 * those vars. If the child is a Laravel CLI in a *different* project (e.g. a
 * claude/codex agent session working on FlipCat), `vlucas/phpdotenv` will NOT
 * override existing env vars by default — so `php artisan migrate` inside that
 * agent's shell runs against CRM Solo's database. That happened once. Once
 * was enough.
 *
 * The fix: `env -i` clears the child's env entirely, then we re-export only
 * an allowlisted minimum that a bash login shell + the CLI need to function
 * (PATH/HOME/USER/SHELL/TERM/LANG/TMPDIR/SSH_AUTH_SOCK + a handful of
 * CRM-specific forward-throughs).
 */
final class SpawnEnvironment
{
    /**
     * @param  array<string, string>  $extra  Additional KEY=value pairs to forward to the child
     *                                        (e.g. CRM_SESSION_TOKEN). Caller supplies raw values;
     *                                        this method shell-quotes them.
     */
    public static function allowlistedPrefix(array $extra = []): string
    {
        $allowlist = [
            // Shell + locale essentials
            'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL',
            'TERM', 'TERMINFO', 'COLORTERM',
            'LANG', 'LC_ALL', 'LC_CTYPE', 'LC_MESSAGES', 'LC_COLLATE',
            'TMPDIR', 'TMP', 'TEMP', 'PWD',
            'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR',
            'SSH_AUTH_SOCK',
            // Claude CLI: macOS keychain auth fallback (see project memory
            // project_claude_path_keychain_fix.md). Forwarded because the
            // claude binary itself needs it; nothing else does.
            'CLAUDE_CODE_OAUTH_TOKEN',
        ];

        $parts = [];
        foreach ($allowlist as $key) {
            $value = getenv($key);
            if ($value === false || $value === '') {
                continue;
            }
            $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        foreach ($extra as $key => $value) {
            if ($value === '') {
                continue;
            }
            $parts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        return 'env -i '.implode(' ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\AgentToken;
use App\Models\User;
use App\Services\Agent\AgentAbilities;
use App\Services\Agent\AgentTokens;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Issues a personal access token for an agent. The plain token is shown once
 * (or written to a 0600 file) and stored only as a hash.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class AgentTokenIssue extends Command
{
    protected $signature = 'agent-tokens:issue
        {user : The user the token acts as: id or email}
        {name : What holds the token, e.g. "claude-code macbook"}
        {--ability=* : read, write:notes, write:tasks, write:time, write:leads, write:month-close, write:clients, write:messages, write:reports, write:invoice-drafts, write:billing, write:trello, write:intake (only by name), or write for every other write group (default: read)}
        {--days= : Days until it expires (default AGENT_TOKEN_DAYS)}
        {--no-expiry : Never expires; revoke it by hand}
        {--to-file= : Write the token to this file (mode 0600) instead of printing it}';

    protected $description = "Issue an agent token acting as a user, in that user's account (operator: names any account's user)";

    public function handle(AgentTokens $tokens): int
    {
        $needle = (string) $this->argument('user');
        $user = ctype_digit($needle)
            ? User::query()->find((int) $needle)
            : User::query()->where('email', $needle)->first();
        if ($user === null || $user->account_id === null) {
            $this->error("No user \"{$needle}\" with an account.");

            return self::FAILURE;
        }

        $requested = (array) $this->option('ability');
        $abilities = $requested === [] ? [AgentAbilities::READ] : $requested;

        $days = $this->option('days') ?? config('agent.token_days');
        if (! $this->option('no-expiry') && (! is_numeric($days) || (int) $days < 1)) {
            $this->error('--days takes a whole number of days, 1 or more.');

            return self::FAILURE;
        }
        $expires = $this->option('no-expiry') ? null : now()->addDays((int) $days);

        $file = $this->option('to-file');
        $handle = null;
        if (is_string($file) && $file !== '') {
            $handle = $this->claimFile($file);
            if ($handle === null) {
                $this->error("{$file} exists or cannot be created; pick a new path so nothing is overwritten.");

                return self::FAILURE;
            }
        }

        try {
            $issued = $tokens->issue($user, (string) $this->argument('name'), $abilities, $expires);
        } catch (InvalidArgumentException $e) {
            if (is_resource($handle)) {
                fclose($handle);
                @unlink((string) $file);
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        /** @var AgentToken $token */
        $token = $issued->accessToken;

        $this->line("Token #{$token->id} \"{$token->name}\" for {$user->email} (account {$user->account_id}).");
        $this->line('Abilities: '.implode(', ', (array) $token->abilities).'. Expires: '.($expires?->toDateString() ?? 'never').'.');

        if (is_resource($handle)) {
            $written = fwrite($handle, $issued->plainTextToken."\n");
            fclose($handle);
            if ($written === false) {
                @unlink($file);
                $token->forceFill(['revoked_at' => now()])->save();
                $this->error("Could not write {$file}; the token was revoked.");

                return self::FAILURE;
            }
            $this->line("Written to {$file} (mode 0600). It is not shown anywhere else.");

            return self::SUCCESS;
        }

        $this->line('Shown once, store it now:');
        $this->line($issued->plainTextToken);

        return self::SUCCESS;
    }

    /**
     * An empty 0600 file at $path, created before the token exists. PHP
     * resolves a symlink before fopen's 'x' (and, on ZTS builds, before
     * link()), so the opened file is accepted only once lstat shows it is
     * the regular file at $path itself.
     *
     * @return resource|null
     */
    private function claimFile(string $path)
    {
        clearstatcache();
        if (is_link($path) || file_exists($path)) {
            return null;
        }

        $umask = umask(0077);
        $handle = @fopen($path, 'x');
        umask($umask);
        if ($handle === false) {
            return null;
        }

        $opened = fstat($handle);
        clearstatcache();
        $found = @lstat($path);
        if ($opened !== false && $found !== false && $this->sameFile($opened, $found)
            && ($found['mode'] & 0170000) === 0100000 && $found['nlink'] === 1 && $found['uid'] === posix_geteuid()) {
            return $handle;
        }

        fclose($handle);
        if ($opened !== false) {
            if ($found !== false && $this->sameFile($opened, $found)) {
                @unlink($path);
            } elseif (($target = realpath($path)) !== false && ($at = @stat($target)) !== false && $this->sameFile($opened, $at)) {
                @unlink($target);
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, int>  $a
     * @param  array<int|string, int>  $b
     */
    private function sameFile(array $a, array $b): bool
    {
        return $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }
}

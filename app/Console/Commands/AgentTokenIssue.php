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
        {--ability=* : read, write:notes, write:tasks, write:time, write:leads, write:month-close, write:clients, write:messages, or write for every write group (default: read)}
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
     * An empty 0600 file at $path, created before the token exists. PHP's
     * fopen resolves symlinks first, so the file is made under a private
     * temporary name and hard-linked into place: link() fails on anything
     * already at the path, a dangling symlink included, and never follows it.
     *
     * @return resource|null
     */
    private function claimFile(string $path)
    {
        $staging = @tempnam(dirname($path), '.crm-token-');
        if ($staging === false) {
            return null;
        }

        $handle = @fopen($staging, 'r+');
        $claimed = $handle !== false && @link($staging, $path);
        @unlink($staging);
        if (! $claimed) {
            if ($handle !== false) {
                fclose($handle);
            }

            return null;
        }

        return $handle;
    }
}

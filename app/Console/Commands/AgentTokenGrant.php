<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\AgentToken;
use App\Services\Agent\AgentTokens;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Adds abilities to an existing agent token, so a write group added after it
 * was issued reaches it without a new token. The owner's explicit step: a
 * group is never granted silently. Expiry and revocation stay as they are.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class AgentTokenGrant extends Command
{
    protected $signature = 'agent-tokens:grant
        {id : Token id from agent-tokens:list}
        {--ability=* : write:notes, write:tasks, write:time, write:leads, write:month-close, write:clients, write:messages, or write for every write group}';

    protected $description = "Add abilities to an agent token (operator: any account's token, by id)";

    public function handle(AgentTokens $tokens): int
    {
        $requested = (array) $this->option('ability');
        if ($requested === []) {
            $this->error('Pass at least one --ability.');

            return self::FAILURE;
        }

        $token = AgentToken::query()->find((int) $this->argument('id'));
        if ($token === null) {
            $this->error('No token #'.$this->argument('id').'.');

            return self::FAILURE;
        }

        try {
            $granted = $tokens->grant($token, $requested);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($granted === null) {
            $this->error("Token #{$token->id} \"{$token->name}\" is revoked; issue a new one.");

            return self::FAILURE;
        }

        $this->line("Token #{$granted->id} \"{$granted->name}\" now has: ".implode(', ', (array) $granted->abilities).'.');

        return self::SUCCESS;
    }
}

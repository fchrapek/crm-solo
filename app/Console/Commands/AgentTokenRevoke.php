<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Attributes\AccountScope;
use App\Models\AgentToken;
use Illuminate\Console\Command;

/**
 * Revokes an agent token by id. The row stays, so audit rows keep its name;
 * the next request carrying it gets 401.
 */
#[AccountScope(AccountScope::OPERATOR)]
final class AgentTokenRevoke extends Command
{
    protected $signature = 'agent-tokens:revoke {id : Token id from agent-tokens:list}';

    protected $description = 'Revoke an agent token (operator: any account\'s token, by id)';

    public function handle(): int
    {
        $token = AgentToken::query()->find((int) $this->argument('id'));
        if ($token === null) {
            $this->error('No token #'.$this->argument('id').'.');

            return self::FAILURE;
        }

        if ($token->isRevoked()) {
            $this->line("Token #{$token->id} \"{$token->name}\" was already revoked.");

            return self::SUCCESS;
        }

        $token->forceFill(['revoked_at' => now()])->save();
        $this->line("Revoked token #{$token->id} \"{$token->name}\".");

        return self::SUCCESS;
    }
}

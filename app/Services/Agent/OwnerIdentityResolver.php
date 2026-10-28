<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use RuntimeException;

/**
 * Resolution for transports that carry no credentials (local CLI, stdio MCP):
 * the account named by `agent.local_account`, or the only account, acted on by
 * its owner. The user stays nullable so an account without users degrades to
 * the CLI's historical NULL attribution instead of failing.
 */
final class OwnerIdentityResolver implements AgentIdentityResolver
{
    public static function localAccount(): Account
    {
        $configured = config('agent.local_account');

        if ($configured !== null) {
            return Account::query()->find($configured)
                ?? throw new RuntimeException("AGENT_LOCAL_ACCOUNT names account {$configured}, which does not exist.");
        }

        $accounts = Account::query()->orderBy('id')->limit(2)->get();
        if ($accounts->count() > 1) {
            throw new RuntimeException('More than one account exists: set AGENT_LOCAL_ACCOUNT to the account id local verbs act in.');
        }

        return $accounts->first()
            ?? throw new RuntimeException('No account exists — seed one before using the agent interface.');
    }

    public function resolve(): AgentIdentity
    {
        $account = self::localAccount();

        $user = $account->users()->where('owner', true)->orderBy('id')->first()
            ?? $account->users()->orderBy('id')->first();

        return new AgentIdentity($account, $user);
    }
}

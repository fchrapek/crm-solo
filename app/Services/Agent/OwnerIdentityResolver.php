<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use RuntimeException;

/**
 * Single-tenant resolution for transports that carry no credentials: the first
 * account, acted on by its owner. The user stays nullable so an account without
 * users degrades to the CLI's historical NULL attribution instead of failing.
 */
final class OwnerIdentityResolver implements AgentIdentityResolver
{
    public function resolve(): AgentIdentity
    {
        $account = Account::query()->orderBy('id')->first();

        if ($account === null) {
            throw new RuntimeException('No account exists — seed one before using the agent interface.');
        }

        $user = $account->users()->where('owner', true)->orderBy('id')->first()
            ?? $account->users()->orderBy('id')->first();

        return new AgentIdentity($account, $user);
    }
}

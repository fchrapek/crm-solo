<?php

declare(strict_types=1);

namespace App\Console\Attributes;

use Attribute;

/**
 * How an artisan command treats accounts; every command in
 * app/Console/Commands declares one (a test fails otherwise).
 *
 * - ACTING: agent-facing or documented for agents. Acts only inside the
 *   acting identity's account (AgentIdentityResolver); an --account option,
 *   where there is one, must name that same account.
 * - OPERATOR: maintenance that legitimately works across accounts (scheduled
 *   syncs, backfills, backups, the demo reset). Its description says so, and
 *   it never takes a record id that crosses accounts without the account
 *   being named.
 * - NONE: touches no account data.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AccountScope
{
    public const string ACTING = 'acting';

    public const string OPERATOR = 'operator';

    public const string NONE = 'none';

    public function __construct(public string $scope) {}
}

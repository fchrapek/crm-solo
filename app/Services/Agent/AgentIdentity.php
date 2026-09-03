<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Account;
use App\Models\User;

/**
 * Who an agent transport is acting as. The CLI verbs historically wrote NULL
 * attribution on every event; anything resolving an identity can now stamp the
 * acting user on lifecycle events and month-close steps.
 */
final readonly class AgentIdentity
{
    public function __construct(
        public Account $account,
        public ?User $user,
    ) {}
}

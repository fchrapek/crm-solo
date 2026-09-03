<?php

declare(strict_types=1);

namespace App\Services\Agent;

/**
 * The transport seam. Local stdio has no credentials, so the default
 * implementation resolves the single owner; a hosted transport binds an
 * implementation that derives the identity from the request token instead.
 */
interface AgentIdentityResolver
{
    public function resolve(): AgentIdentity;
}

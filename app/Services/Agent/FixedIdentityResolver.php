<?php

declare(strict_types=1);

namespace App\Services\Agent;

/**
 * An identity a transport already knows, such as the signed-in user behind
 * the demo crm prompt, handed to the verbs it runs in-process.
 */
final readonly class FixedIdentityResolver implements AgentIdentityResolver
{
    public function __construct(private AgentIdentity $identity) {}

    public function resolve(): AgentIdentity
    {
        return $this->identity;
    }
}

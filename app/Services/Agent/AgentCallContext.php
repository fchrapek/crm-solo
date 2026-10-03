<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\AgentToken;
use Closure;

/**
 * The agent call the current request or command is running, if any. Bound
 * scoped, so Octane and queue workers start every request and job empty.
 * Calls nest (an MCP request, then the tool inside it); the innermost wins.
 */
final class AgentCallContext
{
    /** @var list<AgentCall> */
    private array $stack = [];

    private ?AgentIdentity $identity = null;

    public function current(): ?AgentCall
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(AgentCall $call, Closure $callback): mixed
    {
        $this->begin($call);

        try {
            return $callback();
        } finally {
            $this->end();
        }
    }

    public function begin(AgentCall $call): void
    {
        $this->stack[] = $call;
    }

    public function end(): void
    {
        array_pop($this->stack);
        if ($this->stack === []) {
            $this->identity = null;
        }
    }

    /**
     * The token the request authenticated with, or null on a local transport.
     * Reads only an identity already fixed for the request, never the database.
     */
    public function token(): ?AgentToken
    {
        $resolver = app(AgentIdentityResolver::class);

        return $resolver instanceof FixedIdentityResolver ? $resolver->resolve()->token : null;
    }

    /**
     * Which of $abilities the caller lacks: none on a local transport, the
     * ones its token was not issued with over HTTP.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public function missing(array $abilities): array
    {
        $token = $this->token();
        if ($token === null) {
            return [];
        }

        return array_values(array_filter($abilities, fn (string $ability): bool => ! $token->allows($ability)));
    }

    /** The acting identity, resolved once per outermost call. */
    public function identity(): AgentIdentity
    {
        return $this->identity ??= app(AgentIdentityResolver::class)->resolve();
    }
}

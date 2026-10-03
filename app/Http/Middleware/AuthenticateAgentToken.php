<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AgentToken;
use App\Models\User;
use App\Services\Agent\AgentCall;
use App\Services\Agent\AgentCallContext;
use App\Services\Agent\AgentIdentity;
use App\Services\Agent\AgentIdentityResolver;
use App\Services\Agent\FixedIdentityResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent transports' front door: a bearer personal access token, and
 * nothing else (no session cookie, no Sanctum transient token). The token's
 * user and account become the acting identity for the whole request, and the
 * request runs as an open agent call so its writes are stamped and audited.
 *
 * Usage: `agent.token:mcp-web` or `agent.token:cli-remote` (the AgentCall via).
 */
final class AuthenticateAgentToken
{
    public function __construct(private AgentCallContext $context) {}

    public function handle(Request $request, Closure $next, string $via): Response
    {
        $failKey = 'agent-auth-failed|'.$request->ip();
        if (RateLimiter::tooManyAttempts($failKey, (int) config('agent.failed_per_minute'))) {
            return $this->refuse(429, 'Too many failed token checks. Wait a minute.');
        }

        $identity = $this->identityFor($request->bearerToken());
        if ($identity === null) {
            RateLimiter::hit($failKey, 60);

            return $this->refuse(401, 'Agent token missing, unknown, expired or revoked.');
        }

        $token = $identity->token;
        assert($token instanceof AgentToken);
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('agent_token_id', $token->id);
        $request->setUserResolver(fn (): ?User => $identity->user);

        $session = AgentCall::cleanSessionId($request->header('X-Crm-Session'));
        if ($session === null && $via === AgentCall::VIA_MCP_WEB) {
            $mcpSession = AgentCall::cleanSessionId($request->header('MCP-Session-Id'));
            $session = $mcpSession !== null ? mb_substr('mcp:'.$mcpSession, 0, 128) : null;
        }

        app()->instance(AgentIdentityResolver::class, new FixedIdentityResolver($identity));

        try {
            return $this->context->run(new AgentCall($via, $via, $session), fn (): Response => $next($request));
        } finally {
            app()->forgetInstance(AgentIdentityResolver::class);
        }
    }

    private function identityFor(?string $plain): ?AgentIdentity
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $token = AgentToken::findToken($plain);
        if (! $token instanceof AgentToken || $token->isRevoked() || $token->isExpired()) {
            return null;
        }

        $user = $token->tokenable;
        if (! $user instanceof User || $user->trashed() || $user->account === null) {
            return null;
        }
        if ($token->account_id === null || (int) $token->account_id !== (int) $user->account_id) {
            return null;
        }

        return new AgentIdentity($user->account, $user, $token);
    }

    private function refuse(int $status, string $message): Response
    {
        return response()->json(['error' => $status === 429 ? 'rate_limited' : 'unauthenticated', 'message' => $message], $status);
    }
}

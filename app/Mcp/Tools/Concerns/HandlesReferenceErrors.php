<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Services\Agent\AgentIdentity;
use App\Services\Agent\AgentIdentityResolver;
use App\Services\Agent\ReferenceException;
use Laravel\Mcp\Response;

/**
 * Errors reach the model as JSON, not prose, so an ambiguous name comes back
 * with the candidate ids it needs to retry with instead of a sentence it has
 * to parse.
 */
trait HandlesReferenceErrors
{
    protected function referenceError(ReferenceException $e): Response
    {
        return $this->errorPayload($e->toPayload());
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function invalidArgument(string $message, array $context = []): Response
    {
        return $this->errorPayload([
            'error' => 'invalid_argument',
            'message' => $message,
            ...$context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function errorPayload(array $payload): Response
    {
        return Response::error($this->encode($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payload(array $payload): Response
    {
        return Response::text($this->encode($payload));
    }

    /**
     * Resolved per call, never in the constructor — tool listing must not touch
     * the database.
     */
    protected function identity(): AgentIdentity
    {
        return app(AgentIdentityResolver::class)->resolve();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

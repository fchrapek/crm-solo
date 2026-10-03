<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Services\Agent\ReferenceException;
use RuntimeException;

/**
 * A resource read that cannot be served, carrying the JSON-RPC code the MCP
 * spec gives it: invalid params for a malformed URI variable, resource not
 * found for a record that is missing or outside the acting account.
 */
final class ResourceError extends RuntimeException
{
    public const int INVALID_PARAMS = -32602;

    public const int NOT_FOUND = -32002;

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(string $message, int $code, public readonly array $data)
    {
        parent::__construct($message, $code);
    }

    public static function invalidParams(string $message, string $uri): self
    {
        return new self($message, self::INVALID_PARAMS, ['uri' => $uri]);
    }

    public static function notFound(ReferenceException $e, string $uri): self
    {
        return new self('Resource not found', self::NOT_FOUND, ['uri' => $uri, ...$e->toPayload()]);
    }
}

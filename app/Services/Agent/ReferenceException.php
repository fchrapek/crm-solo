<?php

declare(strict_types=1);

namespace App\Services\Agent;

use RuntimeException;

/**
 * A reference the caller gave (id or name fragment) could not be turned into
 * exactly one record. Carries data rather than a rendered string so each
 * transport can present it natively: the CLI prints candidate lines, MCP
 * returns a candidates array the model can act on.
 */
abstract class ReferenceException extends RuntimeException
{
    public function __construct(
        public readonly string $entity,
        public readonly string $needle,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toPayload(): array;
}

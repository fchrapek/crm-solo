<?php

declare(strict_types=1);

namespace App\Services\Clients;

/** The answer to a billed time write: what happened, the sentence to show, and for a refusal the code an agent branches on. */
final readonly class BilledTimeResult
{
    public const string APPLIED = 'applied';

    public const string UNCHANGED = 'unchanged';

    public const string REFUSED = 'refused';

    public function __construct(
        public string $outcome,
        public string $message,
        public ?string $code = null,
        public ?string $recalculate = null,
    ) {}

    public static function refused(string $code, string $message): self
    {
        return new self(self::REFUSED, $message, $code);
    }

    public function isRefused(): bool
    {
        return $this->outcome === self::REFUSED;
    }
}

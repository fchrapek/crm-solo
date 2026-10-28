<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\ClientMonthClosure;

final readonly class SettlementResult
{
    public function __construct(
        public SettlementOutcome $outcome,
        public string $message,
        public ?ClientMonthClosure $closure = null,
        /** @var array<string, mixed>|null what a toast needs to word the change itself */
        public ?array $toast = null,
    ) {}

    public static function refused(string $message): self
    {
        return new self(SettlementOutcome::Refused, $message);
    }

    public static function unchanged(string $message): self
    {
        return new self(SettlementOutcome::Unchanged, $message);
    }

    public function isRefused(): bool
    {
        return $this->outcome === SettlementOutcome::Refused;
    }
}

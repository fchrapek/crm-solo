<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ClientMonthClosure;

/** A closed month's frozen figures, which a report for that month prints and stores whatever the positions say now. */
final readonly class ClosureFigures
{
    public function __construct(
        public int $closureId,
        public ?float $contracted,
        public float $opening,
        public float $used,
        public ?float $cap,
    ) {}

    public static function of(ClientMonthClosure $closure): self
    {
        $snapshot = $closure->snapshot;

        return new self(
            closureId: $closure->id,
            contracted: isset($snapshot['contracted']) ? (float) $snapshot['contracted'] : null,
            opening: (float) $snapshot['opening'],
            used: (float) $snapshot['used'],
            cap: isset($snapshot['cap']) ? (float) $snapshot['cap'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ClientReport;

final readonly class ReportTransitionResult
{
    public function __construct(
        public ReportTransitionOutcome $outcome,
        public ClientReport $report,
        public string $message,
    ) {}

    public function refused(): bool
    {
        return $this->outcome === ReportTransitionOutcome::Refused;
    }
}

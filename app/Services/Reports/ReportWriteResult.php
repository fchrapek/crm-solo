<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ClientReport;

final readonly class ReportWriteResult
{
    public const string APPLIED = 'applied';

    public const string UNCHANGED = 'unchanged';

    public const string REFUSED = 'refused';

    /** The period already has a report, which is returned instead of a second one. */
    public const string EXISTS = 'exists';

    public function __construct(
        public string $outcome,
        public string $message,
        public ?ClientReport $report = null,
    ) {}

    public static function refused(string $message, ?ClientReport $report = null): self
    {
        return new self(self::REFUSED, $message, $report);
    }

    public function isRefused(): bool
    {
        return $this->outcome === self::REFUSED;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Clients;

use Carbon\CarbonImmutable;

/** Used hours come from the month's closure, else the governing report's actual_hours, else reportable time; logged hours are every entry. */
final readonly class HoursBankMonth
{
    public const string SOURCE_CLOSURE = 'closure';

    public const string SOURCE_REPORT = 'report';

    public const string SOURCE_ENTRIES = 'entries';

    public function __construct(
        public string $period,
        public ?float $contractedHours,
        public float $openingBalanceHours,
        public ?float $rolloverCapHours,
        public float $usedHours,
        public float $loggedHours,
        public ?int $reportId,
        public ?string $reportStatus,
        public string $source = self::SOURCE_ENTRIES,
        public ?int $closureId = null,
        public ?CarbonImmutable $closedAt = null,
        public bool $outsideBank = false,
    ) {}

    public function isClosed(): bool
    {
        return $this->closureId !== null;
    }

    /** Contracted is null without a leading position, so an available balance never reads as a retainer. */
    public function hasRetainer(): bool
    {
        return $this->contractedHours !== null;
    }

    public function availableHours(): float
    {
        return HoursBank::available($this->openingBalanceHours, $this->contractedHours ?? 0.0, $this->rolloverCapHours);
    }

    public function forfeitedHours(): float
    {
        return HoursBank::forfeited($this->openingBalanceHours, $this->contractedHours ?? 0.0, $this->rolloverCapHours);
    }

    public function closingBalanceHours(): float
    {
        if ($this->outsideBank) {
            return round($this->openingBalanceHours, 2);
        }

        return HoursBank::closing($this->availableHours(), $this->usedHours);
    }

    /**
     * @return array{period: string, has_retainer: bool, contracted: ?float, carried: float, cap: ?float, available: float, used: float, remaining: float, logged: float, report_id: ?int, report_status: ?string}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'has_retainer' => $this->hasRetainer(),
            'contracted' => $this->contractedHours,
            'carried' => $this->openingBalanceHours,
            'cap' => $this->rolloverCapHours,
            'available' => $this->availableHours(),
            'used' => $this->usedHours,
            'remaining' => $this->closingBalanceHours(),
            'logged' => $this->loggedHours,
            'report_id' => $this->reportId,
            'report_status' => $this->reportStatus,
        ];
    }
}

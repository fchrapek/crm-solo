<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\ClientMonthClosure;
use App\Models\ClientReport;

/** One period of a client's hours ledger: its figures, the report that went out for it and its settlement state, kept apart. */
final readonly class HoursBankLedgerRow
{
    public function __construct(
        public string $period,
        public string $periodStart,
        public string $periodEnd,
        public bool $wholeMonth,
        public ?HoursBankMonth $month,
        public ?ClientReport $report,
        public ?ClientMonthClosure $closure,
        public bool $active,
        public bool $eligible,
        public bool $beforeBaseline,
    ) {}

    /**
     * The hours a Rozliczenia row shows: the bank's month for a whole month, else the report's own snapshot.
     *
     * @return array{contracted: ?float, used: float, available: float, opening: float, forfeited: float, closing: float, change: float}
     */
    public static function figures(?HoursBankMonth $month, ?ClientReport $report): array
    {
        $opening = $month?->openingBalanceHours ?? (float) $report?->opening_balance_hours;
        $closing = $month?->closingBalanceHours() ?? (float) $report?->closingBalanceHours();

        return [
            'contracted' => $month !== null ? $month->contractedHours : ($report?->contracted_hours !== null ? (float) $report->contracted_hours : null),
            'used' => $month?->usedHours ?? (float) $report?->actual_hours,
            'available' => $month?->availableHours() ?? HoursBank::available($opening, (float) $report?->contracted_hours, $report?->rollover_cap_hours !== null ? (float) $report->rollover_cap_hours : null),
            'opening' => $opening,
            'forfeited' => $month?->forfeitedHours() ?? (float) $report?->forfeitedHours(),
            'closing' => $closing,
            'change' => round($closing - $opening, 2),
        ];
    }
}

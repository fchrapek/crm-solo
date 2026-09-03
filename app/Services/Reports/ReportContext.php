<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\ClientRetainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Immutable input bundle that ReportDataAggregator builds and passes to a
 * ReportComposerInterface implementation. Composers consume this and return
 * markdown — the schema is intentionally narrow so adding new composers
 * doesn't reach back into Eloquent.
 */
final readonly class ReportContext
{
    /**
     * @param  Collection<int, array{id:int, name:string, description:?string, project:?string, completed_at:?string, minutes:int, type:?string}>  $tasks
     * @param  Collection<int, array{id:int, task_id:?int, project:?string, description:?string, start:string, end:?string, minutes:int, source:string}>  $timeEntries
     */
    public function __construct(
        public Client $client,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public string $periodType,
        public ?ClientRetainer $retainer,
        public float $actualHours,
        public Collection $tasks,
        public Collection $timeEntries,
        public string $locale,
        public float $openingBalanceHours = 0.0,
        public ?float $rolloverCapHours = null,
    ) {}

    /**
     * What the client can draw on this period: the balance carried in plus this
     * period's pool, capped at the agreed ceiling.
     */
    public function availableHours(): ?float
    {
        $contracted = $this->contractedHours();

        if ($contracted === null) {
            return null;
        }

        $total = $this->openingBalanceHours + $contracted;

        return $this->rolloverCapHours !== null ? min($total, $this->rolloverCapHours) : $total;
    }

    public function closingBalanceHours(): ?float
    {
        $available = $this->availableHours();

        return $available !== null ? round($available - $this->actualHours, 2) : null;
    }

    public function contractedHours(): ?float
    {
        return $this->retainer !== null ? (float) $this->retainer->monthly_hours : null;
    }

    public function monthlyFee(): ?float
    {
        return $this->retainer?->monthly_fee !== null ? (float) $this->retainer->monthly_fee : null;
    }

    public function overageHourlyRate(): ?float
    {
        return $this->retainer?->overage_hourly_rate !== null ? (float) $this->retainer->overage_hourly_rate : null;
    }

    /**
     * Overage is measured against what the client could actually draw on, not
     * against the monthly pool alone. Someone carrying 4h into a 10h month who
     * books 12h is 2h under, not 2h over.
     */
    public function overageHours(): float
    {
        $available = $this->availableHours();

        if ($available === null) {
            return 0.0;
        }

        return max(0.0, round($this->actualHours - $available, 2));
    }

    public function reportBaselineMarkdown(): ?string
    {
        $value = $this->client->report_baseline_markdown;

        return $value !== null && mb_trim($value) !== '' ? $value : null;
    }

    public function currency(): string
    {
        return $this->retainer?->currency ?? $this->client->currency ?? 'PLN';
    }
}

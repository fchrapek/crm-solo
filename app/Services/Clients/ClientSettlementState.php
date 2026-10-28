<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\ClientMonthClosure;

/** Where a client stands in settlement at one instant: which elapsed months owe a close, which are closed, and the month in play. */
final readonly class ClientSettlementState
{
    /**
     * @param  list<string>  $eligible  elapsed months from the baseline that must be settled, oldest first
     * @param  array<string, ClientMonthClosure>  $closures  every closure of the client, by period
     */
    public function __construct(
        public string $baseline,
        public string $currentPeriod,
        public string $activePeriod,
        public array $eligible,
        public array $closures,
    ) {}

    /** An elapsed month is still open: the running month is not the one to settle. */
    public function openBehind(): bool
    {
        return $this->activePeriod < $this->currentPeriod;
    }

    public function isEligible(string $period): bool
    {
        return in_array($period, $this->eligible, true);
    }

    public function closureFor(string $period): ?ClientMonthClosure
    {
        return $this->closures[$period] ?? null;
    }

    public function latestClosure(): ?ClientMonthClosure
    {
        if ($this->closures === []) {
            return null;
        }
        $periods = array_keys($this->closures);

        return $this->closures[max($periods)];
    }
}

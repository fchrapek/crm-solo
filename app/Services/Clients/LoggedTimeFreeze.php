<?php

declare(strict_types=1);

namespace App\Services\Clients;

/** How far one client's logged time is settled, and whether a local day falls inside it. */
final readonly class LoggedTimeFreeze
{
    public const string CLOSED = 'closed';

    public const string REPORTED = 'reported';

    /**
     * @param  string|null  $closedThrough  the latest closed month, YYYY-MM
     * @param  string|null  $reportedThrough  the last day of the latest finalized or sent report, YYYY-MM-DD
     * @param  string|null  $reportMonth  the month that report starts in, YYYY-MM
     */
    public function __construct(
        public ?string $closedThrough,
        public ?string $reportedThrough,
        public ?string $reportMonth,
    ) {}

    /**
     * Why time logged on $day (a local YYYY-MM-DD) is settled, or null when it is not.
     *
     * @return array{reason: string, month: string}|null
     */
    public function covering(string $day): ?array
    {
        if ($this->closedThrough !== null && mb_substr($day, 0, 7) <= $this->closedThrough) {
            return ['reason' => self::CLOSED, 'month' => $this->closedThrough];
        }
        if ($this->reportedThrough !== null && $day <= $this->reportedThrough) {
            return ['reason' => self::REPORTED, 'month' => (string) $this->reportMonth];
        }

        return null;
    }
}

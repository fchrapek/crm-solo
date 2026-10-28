<?php

declare(strict_types=1);

namespace App\Services\Time;

use App\Services\Clients\LoggedTimeFreeze;
use App\Support\LocalCalendar;
use RuntimeException;

/** A time change that lands on a settled day of a client: its month is closed, or a finalized or sent report covers it. */
final class PeriodSettled extends RuntimeException
{
    /**
     * @param  string  $day  the local day the time counts on, YYYY-MM-DD
     * @param  string  $reason  LoggedTimeFreeze::CLOSED or LoggedTimeFreeze::REPORTED
     * @param  string  $month  YYYY-MM: the closed month, or the month the report starts in
     */
    public function __construct(
        public readonly int $clientId,
        public readonly string $day,
        public readonly string $reason,
        public readonly string $month,
    ) {
        parent::__construct("Time on {$day} is settled ({$reason} {$month}).");
    }

    /** Why the task or project holding this time cannot be deleted; a task is archived instead. */
    public function deleteRefusal(bool $project = false): string
    {
        $month = ['month' => LocalCalendar::monthName($this->month)];

        return match (true) {
            $this->reason === LoggedTimeFreeze::CLOSED && $project => __('This project has time in a closed month (:month), so it cannot be deleted.', $month),
            $this->reason === LoggedTimeFreeze::CLOSED => __('This task has time in a closed month (:month). Archive it instead of deleting.', $month),
            $project => __('This project has time in a finalized or sent report (:month), so it cannot be deleted.', $month),
            default => __('This task has time in a finalized or sent report (:month). Archive it instead of deleting.', $month),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;

/** The calendar days agents name: today, tomorrow or YYYY-MM-DD, all in the display timezone. */
final class AgentDates
{
    /**
     * Local midnight of the day named.
     *
     * @throws AgentRefusal when the value is not a day
     */
    public static function day(string $value, string $field): CarbonImmutable
    {
        $value = mb_strtolower(mb_trim($value));
        $today = LocalCalendar::today();

        if ($value === 'today') {
            return $today;
        }
        if ($value === 'tomorrow') {
            return $today->addDay();
        }

        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalCalendar::timezone())
            : null;
        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $value) {
            throw AgentRefusal::invalid("{$field} takes today, tomorrow or a date as YYYY-MM-DD.", ['field' => $field]);
        }

        return $day;
    }
}

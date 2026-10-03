<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Human calendar boundaries ("today", "this month", "the month just ended")
 * live in the display timezone, while timestamps are stored in UTC. Every
 * day or month boundary is computed here, in the display zone, and handed
 * to queries as a UTC instant, so 00:30 on the 1st in Warsaw belongs to the
 * new month everywhere.
 */
final class LocalCalendar
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** Midnight at the start of the local day, in the display timezone. */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** Today's local calendar date at midnight, for comparing with date columns such as effective_from. */
    public static function todayDate(): Carbon
    {
        return Carbon::parse(self::today()->toDateString(), self::storageTimezone());
    }

    /** The UTC instant the local day began, for "before today" comparisons on timestamps. */
    public static function startOfTodayUtc(): CarbonImmutable
    {
        return self::today()->setTimezone(self::storageTimezone());
    }

    /** The local month in progress, as YYYY-MM. */
    public static function currentMonth(): string
    {
        return self::now()->format('Y-m');
    }

    /** The local month just ended, as YYYY-MM: the default period of a close, an invoice or a report. */
    public static function previousMonth(): string
    {
        return self::now()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
    }

    /** Whether the text is exactly one calendar month as YYYY-MM, 01 to 12. */
    public static function isMonth(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    /**
     * The one parser for a month given as YYYY-MM: its first day at midnight.
     * The format is checked exactly and the date is built with the reset
     * modifier, so no field (the day above all) is taken from the clock: on
     * the 31st, "2026-09" is still September.
     *
     * @param  string|null  $timezone  defaults to the display timezone
     *
     * @throws InvalidArgumentException when the text is not a month
     */
    public static function monthFrom(string $period, ?string $timezone = null): CarbonImmutable
    {
        $first = self::isMonth($period)
            ? CarbonImmutable::createFromFormat('!Y-m', $period, $timezone ?? self::timezone())
            : null;

        if (! $first instanceof CarbonImmutable || $first->format('Y-m') !== $period) {
            throw new InvalidArgumentException("Invalid period \"{$period}\": expected YYYY-MM, a month from 01 to 12.");
        }

        return $first;
    }

    /**
     * A local calendar month (YYYY-MM) as a half-open UTC instant range.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function monthRange(string $period): array
    {
        $first = self::monthFrom($period);

        return self::dateRange($first, $first->endOfMonth());
    }

    /**
     * One local day as a UTC instant range. The date is read off the value's
     * own calendar date, whatever timezone it carries.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayRange(CarbonInterface|string $date): array
    {
        return self::dateRange($date, $date);
    }

    /**
     * Local dates from the first through the last, as a half-open UTC instant
     * range: from the first day's local midnight (inclusive) to the local
     * midnight after the last day (exclusive). Query it as `>= from AND < to`,
     * so every instant falls in exactly one day however the bound is rounded.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dateRange(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return [
            self::localDate($from)->startOfDay()->setTimezone(self::storageTimezone()),
            self::localDate($to)->addDay()->startOfDay()->setTimezone(self::storageTimezone()),
        ];
    }

    private static function localDate(CarbonInterface|string $date): CarbonImmutable
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return CarbonImmutable::parse($day, self::timezone());
    }

    private static function storageTimezone(): string
    {
        return (string) config('app.timezone');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;

/**
 * Hours no message goes out, local time, from the account's settings
 * (`messages.quiet_hours`, default 20:00 to 08:00). A time inside them moves
 * to the end of the window.
 */
final class QuietHours
{
    public static function adjust(Account $account, CarbonImmutable $instant): CarbonImmutable
    {
        [$from, $to] = array_map(self::minutes(...), self::window($account));
        if ($from === $to) {
            return $instant;
        }

        $local = $instant->setTimezone(LocalCalendar::timezone());
        $now = $local->hour * 60 + $local->minute;
        $quiet = $from > $to ? ($now >= $from || $now < $to) : ($now >= $from && $now < $to);
        if (! $quiet) {
            return $instant;
        }

        // setTime keeps the wall clock on the days the clocks change; adding minutes to midnight would not.
        $end = $local->setTime(intdiv($to, 60), $to % 60);
        if ($end->lessThanOrEqualTo($local)) {
            $end = $local->addDay()->setTime(intdiv($to, 60), $to % 60);
        }

        return $end->utc();
    }

    /** @return array{0: string, 1: string} the account's quiet hours as local HH:MM, from and to */
    public static function window(Account $account): array
    {
        $window = (array) ($account->settings['messages']['quiet_hours'] ?? []);

        return [(string) ($window['from'] ?? '20:00'), (string) ($window['to'] ?? '08:00')];
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', array_pad(explode(':', $time, 2), 2, '0'));

        return max(0, min(1439, $hour * 60 + $minute));
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use NumberFormatter;

/**
 * Hours for the owner in the app locale, as resources/js/lib/hours-text.ts prints them: worked hours and balances
 * keep one decimal and a second only when needed ("−16,0 h", "−0,25 h"), retainer terms read as entered ("30 h").
 * Thousands are grouped and minus is a real minus sign.
 */
final class HoursText
{
    public static function signed(float $hours, ?string $locale = null): string
    {
        return self::number($hours, true, $locale).' h';
    }

    public static function plain(float $hours, ?string $locale = null): string
    {
        return self::number($hours, false, $locale).' h';
    }

    public static function number(float $hours, bool $signed = false, ?string $locale = null): string
    {
        $rounded = round($hours, 2);
        $text = self::format(abs($rounded), 1, $locale);
        if ($rounded < 0) {
            return '−'.$text;
        }

        return $signed && $rounded > 0 ? '+'.$text : $text;
    }

    /** A package or a rollover cap as it was entered: "30", "7,5". */
    public static function terms(float $hours, ?string $locale = null): string
    {
        $rounded = round($hours, 2);

        return ($rounded < 0 ? '−' : '').self::format(abs($rounded), 0, $locale);
    }

    private static function format(float $value, int $minDecimals, ?string $locale): string
    {
        $formatter = new NumberFormatter(($locale ?? app()->getLocale()) === 'pl' ? 'pl_PL' : 'en_GB', NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $minDecimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return (string) $formatter->format($value);
    }
}

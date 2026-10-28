<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/** A typed phone number as E.164, Polish numbers by default; null when it cannot be one. */
final class PhoneNumber
{
    public static function e164(?string $raw, string $defaultCountryCode = '48'): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $raw) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+'.mb_substr($digits, 2);
        }
        if (str_starts_with($digits, '+')) {
            return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : null;
        }
        if (preg_match('/^\d{9}$/', $digits)) {
            return '+'.$defaultCountryCode.$digits;
        }
        if (str_starts_with($digits, $defaultCountryCode) && mb_strlen($digits) === mb_strlen($defaultCountryCode) + 9) {
            return '+'.$digits;
        }

        return null;
    }
}

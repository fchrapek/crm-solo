<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;
use Illuminate\Support\Str;

/**
 * What a text costs. One character outside the GSM 7-bit alphabet (ą, ł, an
 * emoji) sends the whole message as UCS-2: 70 characters per SMS instead of
 * 160. With the account's `messages.ascii` on (off by default) such characters
 * are spelled without diacritics before sending.
 */
final class SmsText
{
    private const string GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Characters that take two places in a GSM message. */
    private const string GSM_EXTENDED = "^{}\\[~]|€\f";

    public static function ascii(Account $account): bool
    {
        return (bool) ($account->settings['messages']['ascii'] ?? false);
    }

    public static function prepare(Account $account, string $text): string
    {
        return self::ascii($account) ? self::transliterate($text) : $text;
    }

    public static function transliterate(string $text): string
    {
        return implode('', array_map(function (string $char): string {
            if (self::isGsm($char)) {
                return $char;
            }
            $plain = Str::ascii($char, 'pl');

            return $plain === '' ? $char : $plain;
        }, mb_str_split($text)));
    }

    /** @return array{characters: int, segments: int, unicode: bool} */
    public static function measure(string $text): array
    {
        $chars = mb_str_split($text);
        $unicode = array_filter($chars, fn (string $char): bool => ! self::isGsm($char)) !== [];

        if ($unicode) {
            // UCS-2 counts UTF-16 units: an emoji takes two.
            $units = intdiv(mb_strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'), '8bit'), 2);

            return ['characters' => $units, 'segments' => $units <= 70 ? 1 : (int) ceil($units / 67), 'unicode' => true];
        }

        $septets = array_sum(array_map(fn (string $char): int => str_contains(self::GSM_EXTENDED, $char) ? 2 : 1, $chars));

        return ['characters' => $septets, 'segments' => $septets <= 160 ? 1 : (int) ceil($septets / 153), 'unicode' => false];
    }

    private static function isGsm(string $char): bool
    {
        return str_contains(self::GSM, $char) || str_contains(self::GSM_EXTENDED, $char);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Leads;

use stdClass;

/** The keys a form sends beyond the known fields, within the caps: 20 keys, 40-character keys, 1000-character values, lists of 10 scalars. */
final class IntakeExtras
{
    public const array KNOWN = ['name', 'email', 'message', 'company', 'idempotency_key', 'lang', 'page_url', 'cf-turnstile-response'];

    private const int MAX_KEYS = 20;

    private const int MAX_KEY_LENGTH = 40;

    private const int MAX_VALUE_LENGTH = 1000;

    private const int MAX_LIST = 10;

    /**
     * The extras as text, or null when any of them breaks a cap; the body is decoded with objects, so {} is never a list.
     *
     * @return array<string, string|list<string>>|null
     */
    public static function from(stdClass $body, bool $webhook): ?array
    {
        $extras = [];
        foreach (get_object_vars($body) as $key => $value) {
            $key = (string) $key;
            $control = in_array($key, self::KNOWN, true) || str_starts_with(mb_strtolower($key), 'cf-') || (! $webhook && $key === 'website');
            if ($control || $value === null) {
                continue;
            }
            if (mb_strlen($key) > self::MAX_KEY_LENGTH || preg_match('/\A[A-Za-z0-9_.-]+\z/', $key) !== 1) {
                return null;
            }

            if (is_array($value)) {
                if (count($value) > self::MAX_LIST) {
                    return null;
                }
                $items = [];
                foreach ($value as $item) {
                    $text = self::scalar($item);
                    if ($text === null) {
                        return null;
                    }
                    $items[] = $text;
                }
                $extras[$key] = $items;
            } else {
                $text = self::scalar($value);
                if ($text === null) {
                    return null;
                }
                $extras[$key] = $text;
            }
        }

        return count($extras) > self::MAX_KEYS ? null : $extras;
    }

    private static function scalar(mixed $value): ?string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => mb_trim($value),
            default => null,
        };
        if ($text === null || mb_strlen($text) > self::MAX_VALUE_LENGTH || preg_match('/\p{Cc}/u', $text) === 1) {
            return null;
        }

        return $text;
    }
}

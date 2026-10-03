<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text from data, made safe to print on a terminal: every control character
 * (C0, DEL, C1, so no escape sequence survives), the Unicode line and
 * paragraph separators and the bidi formatting characters are written as
 * visible \uXXXX codes instead of acting. Line breaks are kept only where a
 * caller prints multi-line text; elsewhere they are shown as codes too.
 */
final class LiteralText
{
    /** Characters that reorder or hide text: the bidi embeddings, overrides, isolates and marks. */
    private const string BIDI = '\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}';

    public static function render(?string $text, bool $multiline = false): string
    {
        if ($text === null) {
            return '';
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        $keep = $multiline ? '\n\t' : '\t';
        $pattern = '/[^\P{Cc}'.$keep.']|[\x{2028}\x{2029}'.self::BIDI.']/u';

        return (string) preg_replace_callback(
            $pattern,
            static fn (array $m): string => sprintf('\u%04X', mb_ord($m[0], 'UTF-8')),
            $text,
        );
    }
}

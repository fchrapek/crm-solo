<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Deterministic humanizer gate for CRM content (blader/humanizer, adapted).
 * Every content write passes through this via the HumanizedText cast.
 *
 * Scope is deliberately PUNCTUATION-ONLY: em/en dashes, curly quotes,
 * ellipsis characters, invisible spaces — language-neutral LLM tells that a
 * mechanical transform fixes safely in Polish and English alike. Vocabulary
 * and structure rules (the other ~25 patterns of the skill) live at the
 * GENERATION layer — the humanizer skill in ~/.claude/skills and the report
 * composer prompt — because word-level rewrites by regex distort meaning
 * (house rule: fix the contract, don't pattern-match the output).
 *
 * The content is markdown, so every rule works within one line and only on
 * horizontal whitespace: line breaks, indentation and trailing hard-break
 * spaces pass through unchanged. Some text is never touched at all. Code
 * blocks, fenced or indented, inside lists and blockquotes too, are found by
 * league/commonmark's block parser (MarkdownBlocks). Within each paragraph or
 * heading a left-to-right scan protects, in markdown's precedence, inline
 * code spans (also across lines), link destinations (a code span inside one
 * is part of it), reference definition destinations, and any
 * whitespace-free token containing a slash, which covers URLs and file
 * paths. Text with more lines than MarkdownBlocks parses is left as it is.
 *
 * A dash opening a line (after indentation or a blockquote marker) becomes
 * a "- " list item. Running clean() twice gives the same text as once, and
 * a match that fails leaves the text as it was.
 */
final class Humanizer
{
    private const DASHES = '[\x{2014}\x{2013}]';

    /** Private-use characters that stand in for a protected region; the first one absent from the text is used. */
    private const SENTINELS = ["\u{E000}", "\u{E001}", "\u{E002}", "\u{E003}", "\u{E004}", "\u{E005}", "\u{E006}", "\u{E007}"];

    /** ASCII bytes that end a protected token: whitespace, brackets, quotes and backticks. */
    private const TOKEN_STOP = " \t\r\n\f\v<>()[]\"'`";

    /** Curly quotes, which also end a protected token. */
    private const TOKEN_STOP_QUOTES = ["\u{201C}", "\u{201D}", "\u{201E}", "\u{2018}", "\u{2019}", "\u{201A}", "\u{00AB}", "\u{00BB}"];

    public static function clean(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $sentinel = null;
        foreach (self::SENTINELS as $candidate) {
            if (! str_contains($text, $candidate)) {
                $sentinel = $candidate;

                break;
            }
        }

        $blocks = MarkdownBlocks::parse($text);
        $parts = preg_split('/(\r\n|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($sentinel === null || $blocks === null || $parts === false) {
            return $text;
        }

        // Code lines pass through; a paragraph or heading is cleaned as one piece,
        // so a code span can run across its lines; any other line on its own.
        $out = '';
        $lines = intdiv(count($parts) + 1, 2);
        for ($line = 1; $line <= $lines; $line++) {
            $index = ($line - 1) * 2;
            if (isset($blocks->codeLines[$line])) {
                $out .= $parts[$index].($parts[$index + 1] ?? '');

                continue;
            }

            $last = min($blocks->groups[$line] ?? $line, $lines);
            $group = '';
            for (; $line <= $last; $line++) {
                $index = ($line - 1) * 2;
                $group .= $parts[$index].($parts[$index + 1] ?? '');
            }
            $line--;
            $out .= self::cleanGroup($group, $sentinel);
        }

        return $out;
    }

    /**
     * Mask every protected region with the sentinel, clean the masked text,
     * then put the regions back in order.
     */
    private static function cleanGroup(string $text, string $sentinel): string
    {
        [$masked, $regions] = self::mask($text, $sentinel);

        $cleaned = self::cleanProse(self::cleanLines($masked));
        if (mb_substr_count($cleaned, $sentinel) * 2 !== count($regions)) {
            return $text;
        }

        $out = '';
        foreach (explode($sentinel, $cleaned) as $n => $piece) {
            $out .= $n === 0 ? $piece : mb_substr($text, $regions[2 * $n - 2], $regions[2 * $n - 1], '8bit').$piece;
        }

        return $out;
    }

    /**
     * One left-to-right pass over bytes. Whatever starts first wins, as in
     * markdown: a code span opened before a link keeps the link inside it, and
     * a link destination swallows a code span written inside it.
     *
     * @return array{0: string, 1: list<int>} the masked text, and each region's offset and length
     */
    private static function mask(string $text, string $sentinel): array
    {
        $length = mb_strlen($text, '8bit');

        // Backtick runs as flat lists, and for each the next run of the same length.
        $runAt = [];
        $runLength = [];
        // strcspn and strspn, because mb_strpos rescans the string on every call.
        for ($at = strcspn($text, '`'); $at < $length; $at += strcspn($text, '`', $at)) {
            $run = strspn($text, '`', $at);
            $runAt[] = $at;
            $runLength[] = $run;
            $at += $run;
        }
        $runs = count($runAt);
        $closer = $runs > 0 ? array_fill(0, $runs, -1) : [];
        $seen = [];
        for ($r = $runs - 1; $r >= 0; $r--) {
            $closer[$r] = $seen[$runLength[$r]] ?? -1;
            $seen[$runLength[$r]] = $r;
        }

        $masked = '';
        $regions = [];
        $prose = 0;
        $i = 0;
        $r = 0;
        while (($i += strcspn($text, '`]/', $i)) < $length) {
            $region = null;
            if ($text[$i] === '`') {
                while ($r < $runs && $runAt[$r] < $i) {
                    $r++;
                }
                // The tail of a run a protected region cut through, or a run with no partner, is literal.
                if ($r >= $runs || $runAt[$r] !== $i || $closer[$r] < 0) {
                    $i += strspn($text, '`', $i);

                    continue;
                }
                $region = [$i, $runAt[$closer[$r]] + $runLength[$closer[$r]]];
            } elseif ($text[$i] === ']') {
                $next = $text[$i + 1] ?? '';
                if ($next === '(') {
                    $end = self::destinationEnd($text, $i + 2, $length);
                    $region = $end > $i + 2 ? [$i + 2, $end] : null;
                } elseif ($next === ':' && self::closesReferenceLabel($text, $i)) {
                    $start = $i + 2 + strspn($text, " \t", $i + 2);
                    $end = $start + strcspn($text, " \t\r\n", $start);
                    $region = $end > $start ? [$start, $end] : null;
                }
                if ($region === null) {
                    $i++;

                    continue;
                }
            } else {
                $region = self::slashToken($text, $i, $prose, $length);
            }

            [$start, $end] = $region;
            $masked .= mb_substr($text, $prose, $start - $prose, '8bit').$sentinel;
            $regions[] = $start;
            $regions[] = $end - $start;
            $prose = $i = $end;
        }

        return [$masked.mb_substr($text, $prose, null, '8bit'), $regions];
    }

    /** Where a link destination starting at $start ends: angle brackets, or balanced parentheses up to whitespace. */
    private static function destinationEnd(string $text, int $start, int $length): int
    {
        if (($text[$start] ?? '') === '<') {
            $close = $start + 1 + strcspn($text, "<>\r\n", $start + 1);

            return ($text[$close] ?? '') === '>' ? $close + 1 : $start;
        }

        $depth = 0;
        for ($at = $start; $at < $length; $at++) {
            $byte = $text[$at];
            if ($byte === '\\') {
                $at++;
            } elseif (ord($byte) <= 32 || ($byte === ')' && $depth === 0)) {
                break;
            } elseif ($byte === '(') {
                $depth++;
            } elseif ($byte === ')') {
                $depth--;
            }
        }

        return min($at, $length);
    }

    /** Whether the "]" at $at closes a reference definition label opened at the start of its line. */
    private static function closesReferenceLabel(string $text, int $at): bool
    {
        $from = max(0, $at - 1000);

        return preg_match('/(?:\A|[\r\n])[ \t]{0,3}\[[^\]\r\n]*\z/', mb_substr($text, $from, $at - $from, '8bit')) === 1;
    }

    /**
     * The whitespace-free token around the slash at $at, never reaching back
     * past $floor, the end of the last protected region.
     *
     * @return array{0: int, 1: int}
     */
    private static function slashToken(string $text, int $at, int $floor, int $length): array
    {
        $start = $at;
        while ($start > $floor && ! str_contains(self::TOKEN_STOP, $text[$start - 1])) {
            $start--;
        }
        $end = $at + strcspn($text, self::TOKEN_STOP, $at);

        $headStart = $start;
        $head = mb_substr($text, $start, $at - $start, '8bit');
        $tail = mb_substr($text, $at, $end - $at, '8bit');
        foreach (self::TOKEN_STOP_QUOTES as $quote) {
            if (($q = mb_strrpos($head, $quote, 0, '8bit')) !== false) {
                $start = max($start, $headStart + $q + mb_strlen($quote, '8bit'));
            }
            if (($q = mb_strpos($tail, $quote, 0, '8bit')) !== false) {
                $end = min($end, $at + $q);
            }
        }

        return [$start, min($end, $length)];
    }

    private static function cleanLines(string $text): string
    {
        $d = self::DASHES;
        $lineStart = '(?<![^\r\n])';
        $lineEnd = '(?=[\r\n]|\z)';

        // A line of dashes only keeps its count, so a rule stays a rule.
        $text = self::replaceCallback(
            '/'.$lineStart.'([ \t]*)('.$d.'+)([ \t]*)'.$lineEnd.'/u',
            fn (array $m): string => $m[1].str_repeat('-', mb_strlen($m[2])).$m[3],
            $text,
        );
        // A dash opening the line is a list bullet; keep the indentation in front of it.
        $text = self::replace('/'.$lineStart.'([ \t]*(?:>[ \t]?)*)'.$d.'++[ \t]*(?=\S)/u', '$1- ', $text);

        // A dash closing the line keeps whatever trailing spaces follow it (a hard break).
        return self::replace('/(?<=\S)[ \t]*'.$d.'+(?=[ \t]*'.$lineEnd.')/u', ' -', $text);
    }

    private static function cleanProse(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $d = self::DASHES;

        // Invisible artifacts: zero-width chars, soft hyphen; NBSP and thin spaces → space.
        // A zero-width joiner between two emoji is part of the emoji (👩‍💻), so only stray ones go.
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{FEFF}", "\u{00AD}"], '', $text);
        $text = self::replace('/(?<![\p{Extended_Pictographic}\x{FE0F}\x{1F3FB}-\x{1F3FF}])\x{200D}|\x{200D}(?!\p{Extended_Pictographic})/u', '', $text);
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);

        // A dash between two digits is a range and stays tight (9–17 → 9-17).
        $text = self::replace('/(\d)[ \t]*'.$d.'+[ \t]*(?=\d)/u', '$1-', $text);
        // Any other dash becomes a spaced hyphen, absorbing only the spaces beside it.
        $text = self::replace('/[ \t]*'.$d.'+[ \t]*/u', ' - ', $text);

        // Curly quotes/apostrophes → straight; single-char ellipsis → dots.
        $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}", "\u{00BB}", "\u{00AB}"], '"', $text);
        $text = str_replace(["\u{2018}", "\u{2019}", "\u{201A}"], "'", $text);

        return str_replace("\u{2026}", '...', $text);
    }

    /** A failed match (bad UTF-8, an exhausted limit) leaves the text as it was, never empty. */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }

    /**
     * @param  callable(array<int|string, string>): string  $callback
     */
    private static function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        return preg_replace_callback($pattern, $callback, $subject) ?? $subject;
    }
}

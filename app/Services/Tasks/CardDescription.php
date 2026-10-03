<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Services\MarkdownBlocks;

/**
 * Deterministic cleanup of a task description for agents: line endings,
 * trailing whitespace, the empty link titles Trello writes, and links whose
 * text is their own URL. Link rewriting touches prose only: code blocks,
 * code spans and backslash escapes are copied as they are. It only ever
 * removes markup noise, never a word, and running it twice changes nothing.
 *
 * Parsing is linear (one pass to find code spans and brackets, one to read
 * links), so no input length can make it fail and drop the text.
 */
final class CardDescription
{
    /** Letters and digits a description needs, links aside, to say something. */
    private const int SUBSTANTIVE_CHARACTERS = 20;

    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = MarkdownBlocks::parse($text);

        $out = self::mapSegments($text, $blocks, fn (string $prose): string => $blocks === null
            ? $prose
            : implode('', array_map(self::rewrite(...), self::tokens($prose))));

        return mb_rtrim($out, "\n");
    }

    /**
     * Every http(s) URL in a normalised description outside code, in source
     * order, once each: markdown links and images with their text, bare URLs
     * with none.
     *
     * @return list<array{url: string, text: string|null}>
     */
    public static function links(string $normalized): array
    {
        $links = [];
        foreach (self::proseSegments($normalized) as $prose) {
            foreach (self::tokens($prose) as $token) {
                if ($token['type'] === 'link') {
                    if (preg_match('~^https?://~i', $token['dest']) === 1) {
                        $label = mb_trim(self::unescape($token['label']));
                        $links[] = ['url' => $token['dest'], 'text' => $label !== '' ? $label : null];
                    }
                } elseif ($token['type'] === 'text') {
                    foreach (self::bareUrls($token['raw']) as $url) {
                        $links[] = ['url' => $url, 'text' => null];
                    }
                }
            }
        }

        $seen = [];

        return array_values(array_filter($links, function (array $link) use (&$seen): bool {
            if (isset($seen[$link['url']])) {
                return false;
            }

            return $seen[$link['url']] = true;
        }));
    }

    /** Whether the description says something once its URLs are taken out. */
    public static function isSubstantive(string $normalized): bool
    {
        $count = 0;
        $blocks = MarkdownBlocks::parse($normalized);
        foreach (explode("\n", $normalized) as $i => $line) {
            if (isset($blocks?->codeLines[$i + 1])) {
                $count += self::letters($line);
            }
        }

        foreach (self::proseSegments($normalized) as $prose) {
            foreach (self::tokens($prose) as $token) {
                $count += match ($token['type']) {
                    'link' => self::unescape($token['label']) === $token['dest'] ? 0 : self::letters($token['label']),
                    'text' => self::letters(str_replace(self::bareUrls($token['raw']), ' ', $token['raw'])),
                    default => self::letters($token['raw']),
                };
            }
        }

        return $count >= self::SUBSTANTIVE_CHARACTERS;
    }

    /**
     * Rebuilds the text line by line: code lines as they are, every run of
     * prose lines trimmed of trailing whitespace and passed through $prose.
     *
     * @param  callable(string): string  $prose
     */
    private static function mapSegments(string $text, ?MarkdownBlocks $blocks, callable $prose): string
    {
        $out = [];
        $run = [];
        foreach (explode("\n", $text) as $i => $line) {
            if (isset($blocks?->codeLines[$i + 1])) {
                if ($run !== []) {
                    $out[] = $prose(implode("\n", $run));
                    $run = [];
                }
                $out[] = $line;

                continue;
            }
            $run[] = mb_rtrim($line, " \t");
        }
        if ($run !== []) {
            $out[] = $prose(implode("\n", $run));
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    private static function proseSegments(string $text): array
    {
        $segments = [];
        self::mapSegments($text, MarkdownBlocks::parse($text), function (string $prose) use (&$segments): string {
            $segments[] = $prose;

            return $prose;
        });

        return $segments;
    }

    /**
     * Splits prose into text, code spans and links. Bytes are scanned (the
     * '8bit' length and substrings), which is safe in UTF-8 since every
     * delimiter is ASCII.
     *
     * @return list<array<string, mixed>>
     */
    private static function tokens(string $s): array
    {
        $len = mb_strlen($s, '8bit');
        [$spans, $close] = self::structure($s, $len);

        $tokens = [];
        $textStart = 0;
        $flush = function (int $end) use (&$tokens, &$textStart, $s): void {
            if ($end > $textStart) {
                $tokens[] = ['type' => 'text', 'raw' => mb_substr($s, $textStart, $end - $textStart, '8bit')];
            }
        };

        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\') {
                $i += 2;

                continue;
            }
            if (isset($spans[$i])) {
                $flush($i);
                $tokens[] = ['type' => 'code', 'raw' => mb_substr($s, $i, $spans[$i] - $i, '8bit')];
                $i = $textStart = $spans[$i];

                continue;
            }
            if ($c === '[' && isset($close[$i])) {
                $tail = self::linkTail($s, $close[$i] + 1, $len);
                if ($tail !== null) {
                    $flush($i);
                    $tokens[] = [
                        'type' => 'link',
                        'raw' => mb_substr($s, $i, $tail['end'] - $i, '8bit'),
                        'image' => $i > 0 && $s[$i - 1] === '!' && ($i < 2 || $s[$i - 2] !== '\\'),
                        'label' => mb_substr($s, $i + 1, $close[$i] - $i - 1, '8bit'),
                        'dest' => $tail['dest'],
                        'angle' => $tail['angle'],
                        'title' => $tail['title'],
                    ];
                    $i = $textStart = $tail['end'];

                    continue;
                }
            }
            $i++;
        }
        $flush($len);

        return $tokens;
    }

    /**
     * Code spans (start => end) and matched brackets (open => close), with
     * escapes and code spans honoured: one pass, plus a sorted lookup per
     * backtick run length.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function structure(string $s, int $len): array
    {
        $runs = [];
        for ($i = 0; $i < $len;) {
            if ($s[$i] === '`') {
                $j = $i;
                while ($j < $len && $s[$j] === '`') {
                    $j++;
                }
                $runs[$j - $i][] = $i;
                $i = $j;

                continue;
            }
            $i++;
        }

        $spans = [];
        $stack = [];
        $close = [];
        for ($i = 0; $i < $len;) {
            $c = $s[$i];
            if ($c === '\\') {
                $i += 2;

                continue;
            }
            if ($c === '`') {
                $j = $i;
                while ($j < $len && $s[$j] === '`') {
                    $j++;
                }
                $closer = self::nextRun($runs[$j - $i] ?? [], $j);
                if ($closer !== null) {
                    $spans[$i] = $closer + ($j - $i);
                    $i = $spans[$i];

                    continue;
                }
                $i = $j;

                continue;
            }
            if ($c === '[') {
                $stack[] = $i;
            } elseif ($c === ']' && $stack !== []) {
                $close[array_pop($stack)] = $i;
            }
            $i++;
        }

        return [$spans, $close];
    }

    /**
     * The first backtick run of the same length starting at or after $from.
     *
     * @param  list<int>  $positions  ascending
     */
    private static function nextRun(array $positions, int $from): ?int
    {
        $lo = 0;
        $hi = count($positions);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($positions[$mid] < $from) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $positions[$lo] ?? null;
    }

    /**
     * Reads `(destination "title")` right after a link's closing bracket.
     *
     * @return array{dest: string, angle: bool, title: string|null, end: int}|null
     */
    private static function linkTail(string $s, int $p, int $len): ?array
    {
        if ($p >= $len || $s[$p] !== '(') {
            return null;
        }

        $q = self::skipSpace($s, $p + 1, $len);
        $angle = $q < $len && $s[$q] === '<';
        if ($angle) {
            $start = $q + 1;
            for ($q = $start; $q < $len && $s[$q] !== '>'; $q++) {
                if ($s[$q] === "\n" || $s[$q] === '<') {
                    return null;
                }
                if ($s[$q] === '\\') {
                    $q++;
                }
            }
            if ($q >= $len) {
                return null;
            }
            $dest = mb_substr($s, $start, $q - $start, '8bit');
            $q++;
        } else {
            $start = $q;
            $depth = 0;
            while ($q < $len) {
                $c = $s[$q];
                if ($c === '\\' && $q + 1 < $len) {
                    $q += 2;

                    continue;
                }
                if (ord($c) <= 32) {
                    break;
                }
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                }
                $q++;
            }
            if ($depth !== 0) {
                return null;
            }
            $dest = mb_substr($s, $start, $q - $start, '8bit');
        }

        $afterDest = $q;
        $q = self::skipSpace($s, $q, $len);
        $title = null;
        if ($q > $afterDest && $q < $len && in_array($s[$q], ['"', "'", '('], true)) {
            $closer = $s[$q] === '(' ? ')' : $s[$q];
            $t = $q + 1;
            while ($t < $len && $s[$t] !== $closer) {
                if ($s[$t] === '\\') {
                    $t++;
                } elseif ($closer === ')' && $s[$t] === '(') {
                    return null;
                }
                $t++;
            }
            if ($t >= $len) {
                return null;
            }
            $title = mb_substr($s, $q + 1, $t - $q - 1, '8bit');
            $q = self::skipSpace($s, $t + 1, $len);
        }

        if ($q >= $len || $s[$q] !== ')') {
            return null;
        }

        return ['dest' => $dest, 'angle' => $angle, 'title' => $title, 'end' => $q + 1];
    }

    /** Spaces and tabs, and at most one line break, as a link may hold. */
    private static function skipSpace(string $s, int $q, int $len): int
    {
        $newline = false;
        while ($q < $len && ($s[$q] === ' ' || $s[$q] === "\t" || ($s[$q] === "\n" && ! $newline))) {
            $newline = $newline || $s[$q] === "\n";
            $q++;
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private static function rewrite(array $token): string
    {
        if ($token['type'] !== 'link') {
            return $token['raw'];
        }

        $blankTitle = $token['title'] !== null && self::isBlank($token['title']);
        $title = $blankTitle ? null : $token['title'];

        if (! $token['image'] && $title === null && $token['dest'] !== '' && self::unescape($token['label']) === $token['dest']) {
            return $token['dest'];
        }

        if ($blankTitle) {
            return '['.$token['label'].']('.($token['angle'] ? '<'.$token['dest'].'>' : $token['dest']).')';
        }

        return $token['raw'];
    }

    /** Empty, or only whitespace and zero-width marks. */
    private static function isBlank(string $title): bool
    {
        return mb_trim(str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}"], '', $title)) === '';
    }

    /** Backslash escapes of ASCII punctuation, read as the character. */
    private static function unescape(string $label): string
    {
        return (string) preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $label);
    }

    /**
     * Bare URLs in plain text, without sentence punctuation after them or a
     * closing bracket they never opened.
     *
     * @return list<string>
     */
    private static function bareUrls(string $text): array
    {
        if (! preg_match_all('~https?://[^\s<>"\'\[\]]+~i', $text, $matches)) {
            return [];
        }

        return array_map(function (string $url): string {
            $url = mb_rtrim($url, '.,;:!?');
            while (str_ends_with($url, ')') && mb_substr_count($url, ')') > mb_substr_count($url, '(')) {
                $url = mb_rtrim(mb_substr($url, 0, -1), '.,;:!?');
            }

            return $url;
        }, $matches[0]);
    }

    private static function letters(string $text): int
    {
        $count = preg_match_all('/[\p{L}\p{N}]/u', $text);

        return $count === false ? (int) preg_match_all('/[A-Za-z0-9]/', $text) : $count;
    }
}

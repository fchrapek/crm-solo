<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Removes ad click identifiers from what a lead stores: the landing URL, the
 * tracking map and free-text notes (message included). Used at every kiwwwi
 * import and by the kiwwwi:scrub-click-ids backfill.
 *
 * Only per-click identifiers go. Campaign-level params (utm_*, gad_source,
 * gad_campaignid) are not personal identifiers and stay, so the notes still
 * show which campaign produced the lead.
 *
 * Every entry point takes a $keep list: the click ids whose issuing service
 * the visitor granted (MarketingConsent::keptClickIds). An empty list strips
 * all of them.
 *
 * One key parser (canonicalKey) serves detection and scrubbing alike, so a
 * key is recognised the same way everywhere: case-insensitive, percent-
 * decoded (%67clid, double-encoded %2567clid), array suffix dropped
 * (gclid[] / gclid%5B%5D / gclid[0]). Query strings, fragments (#gclid=...,
 * #/route?gclid=...), `;` separators, URLs nested inside encoded param
 * values (…%3Fgclid%3D…) and bare pairs in free text are all covered.
 */
final class ClickIdScrubber
{
    public const PARAMS = ['gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid'];

    /**
     * key=value anywhere in text. Group 1: an optional leading separator
     * (plain or percent-encoded), removed along with the pair (`?`/`#` are
     * kept when another pair follows). Group 2: the
     * raw key, which may carry %XX escapes (not encoded separators) and []
     * suffixes. Group 3: `=` in
     * any encoding. Group 4: the value, up to the next separator. Group 5
     * (lookahead): the separator that follows, if any.
     */
    private const PAIR = '~([?#&;]|%3F|%23|%26|%2526|%3B)?((?:[A-Za-z0-9_.\-\[\]]|%(?!3[BDFbdf]|2[36]|25(?:2[36]|3[BDFbdf]))[0-9A-Fa-f]{2})+)(=|%3D|%253D)((?:(?!%26|%2526|%3B|%3F|%23)[^\s&;#?,"\'<>])*)(?=([&;]|%26|%2526|%3B)?)~i';

    /** JSON-ish or prose form: "gclid": "abc", gclid: abc. */
    private const COLON = '~(["\']?)\b(gclid|gbraid|wbraid|dclid|fbclid|msclkid)\1\s*:\s*["\']?[A-Za-z0-9_.\-]{4,}["\']?~i';

    private const URL = '~(?:https?://|www\.)[^\s<>"\']+~iu';

    /**
     * The one canonical form of a query/fragment key.
     */
    public static function canonicalKey(string $raw): string
    {
        $key = $raw;
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode(str_replace('+', ' ', $key));
            if ($decoded === $key) {
                break;
            }
            $key = $decoded;
        }

        $key = mb_strtolower(mb_trim($key));
        $key = mb_ltrim($key, "&;?# \t");
        // Array syntax: gclid[] / gclid[0] / gclid[x] all feed PHP's $_GET['gclid'].
        $key = (string) preg_replace('/\[.*\z/s', '', $key);

        return mb_trim($key);
    }

    /**
     * @param  list<string>  $keep
     */
    public static function isClickIdKey(string $raw, array $keep = []): bool
    {
        $key = self::canonicalKey($raw);

        return in_array($key, self::PARAMS, true) && ! in_array($key, $keep, true);
    }

    /**
     * @param  array<array-key, mixed>  $tracking
     * @param  list<string>  $keep
     * @return array<array-key, mixed>
     */
    public static function tracking(array $tracking, array $keep = []): array
    {
        return array_filter(
            $tracking,
            fn ($key): bool => ! self::isClickIdKey((string) $key, $keep),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param  list<string>  $keep
     */
    public static function url(string $url, array $keep = []): string
    {
        $hashAt = mb_strpos($url, '#');
        $fragment = $hashAt === false ? null : mb_substr($url, $hashAt + 1);
        $beforeHash = $hashAt === false ? $url : mb_substr($url, 0, $hashAt);

        $queryAt = mb_strpos($beforeHash, '?');
        $out = $queryAt === false ? $beforeHash : mb_substr($beforeHash, 0, $queryAt);

        if ($queryAt !== false) {
            $query = self::pairs(mb_substr($beforeHash, $queryAt + 1), $keep);
            if ($query !== '') {
                $out .= '?'.$query;
            }
        }

        if ($fragment !== null) {
            $fragment = self::fragment($fragment, $keep);
            if ($fragment !== '') {
                $out .= '#'.$fragment;
            }
        }

        // Last pass for ids nested in encoded values (redirect=…%3Fgclid%3D…).
        return self::bare($out, $keep);
    }

    /**
     * Notes are free text, so this works line by line: "Tracking: k=v, ..."
     * lines lose their click-id pairs (a line left empty goes with its line
     * break), URLs anywhere lose their click-id params, then bare pairs and
     * "gclid: value" forms go wherever they are. Line breaks are kept as
     * written, so text without click ids comes back byte-identical.
     *
     * @param  list<string>  $keep
     */
    public static function text(string $text, array $keep = []): string
    {
        $parts = preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        /** @var list<array{0: string, 1: string}> $lines [line break before it, line] */
        $lines = [];
        $break = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $break = $part;

                continue;
            }

            if (str_starts_with($part, 'Tracking: ')) {
                $pairs = array_filter(
                    explode(', ', mb_substr($part, mb_strlen('Tracking: '))),
                    fn (string $pair): bool => ! self::isClickIdKey(explode('=', $pair, 2)[0], $keep)
                );
                if ($pairs === []) {
                    continue;
                }
                $part = 'Tracking: '.implode(', ', $pairs);
            } else {
                $part = (string) preg_replace_callback(
                    self::URL,
                    fn (array $match): string => self::url($match[0], $keep),
                    $part
                );
            }

            $lines[] = [$lines === [] ? '' : $break, $part];
        }

        $out = implode('', array_map(fn (array $line): string => $line[0].$line[1], $lines));
        $out = self::bare($out, $keep);

        return (string) preg_replace_callback(
            self::COLON,
            fn (array $m): string => in_array(mb_strtolower($m[2]), $keep, true) ? $m[0] : '',
            $out
        );
    }

    /**
     * @param  list<string>  $keep
     */
    public static function containsClickId(string $text, array $keep = []): bool
    {
        return self::text($text, $keep) !== $text;
    }

    /**
     * Canonical click-id keys present in the text (for reporting only;
     * detection itself compares text() against the original).
     *
     * @return list<string>
     */
    public static function found(string $text): array
    {
        $found = [];

        preg_match_all(self::PAIR, $text, $pairs);
        foreach ($pairs[2] as $key) {
            $canonical = self::canonicalKey($key);
            if (in_array($canonical, self::PARAMS, true)) {
                $found[$canonical] = true;
            }
        }

        preg_match_all(self::COLON, $text, $colon);
        foreach ($colon[2] as $key) {
            $found[mb_strtolower($key)] = true;
        }

        return array_values(array_intersect(self::PARAMS, array_keys($found)));
    }

    /**
     * @param  list<string>  $keep
     */
    private static function bare(string $text, array $keep): string
    {
        $out = (string) preg_replace_callback(
            self::PAIR,
            function (array $m) use ($keep): string {
                if (! self::isClickIdKey($m[2], $keep)) {
                    return $m[0];
                }

                // "?gclid=1&a=2": keep the `?`/`#` so the next pair moves up
                // (the orphan `&` is folded below). "?gclid=1" alone: drop it.
                $separator = mb_strtoupper($m[1]);
                if (in_array($separator, ['?', '#', '%3F', '%23'], true) && ($m[5] ?? '') !== '') {
                    return $m[1];
                }

                return '';
            },
            $text
        );

        return $out === $text ? $text : (string) preg_replace('~([?#]|%3F|%23)(?:[&;]|%26|%2526|%3B)+~i', '$1', $out);
    }

    /**
     * @param  list<string>  $keep
     */
    private static function fragment(string $fragment, array $keep): string
    {
        // Hash routes: #/path?gclid=...
        $queryAt = mb_strpos($fragment, '?');
        if ($queryAt !== false) {
            $query = self::pairs(mb_substr($fragment, $queryAt + 1), $keep);

            return mb_substr($fragment, 0, $queryAt).($query === '' ? '' : '?'.$query);
        }

        return str_contains($fragment, '=') ? self::pairs($fragment, $keep) : $fragment;
    }

    /**
     * Filter a k=v list split on & or ; (both are separators to some
     * parsers), keeping each surviving pair's own separator.
     *
     * @param  list<string>  $keep
     */
    private static function pairs(string $query, array $keep): string
    {
        $parts = preg_split('/(&amp;|[&;])/i', $query, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$query];

        $out = '';
        $separator = '';
        $removed = false;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $separator = $part;

                continue;
            }

            if ($part !== '' && self::isClickIdKey(explode('=', $part, 2)[0], $keep)) {
                $removed = true;

                continue;
            }

            if ($part !== '') {
                $out .= ($out === '' ? '' : $separator).$part;
            }
        }

        return $removed ? $out : $query;
    }
}

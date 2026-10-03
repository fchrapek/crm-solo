<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\MarkdownBlocks;

/**
 * The report's billing summary, the one place a balance is printed. Both the
 * structured composer and an opening-balance edit render it from here, so
 * the figures in the body are always the figures stored on the report.
 */
final class BillingSummary
{
    public const REWRITTEN = 'rewritten';

    public const MISSING = 'missing';

    public const AMBIGUOUS = 'ambiguous';

    private const LOCALES = ['pl', 'en'];

    /**
     * Four plain lines: what rolled in, this period's pool, what was used,
     * what rolls out. Plus one line when the agreed carry-over cap clipped
     * the balance, since those hours are gone and the client should see why.
     *
     * Monthly reports use fixed month labels ("Bilans na start miesiąca"),
     * the same in every month: no month name to decline, no wording for a
     * model to vary. Other periods keep the generic labels.
     *
     * @return list<string>
     */
    public static function lines(float $opening, float $contracted, float $actual, ?float $cap, ?string $locale = null, string $periodType = 'month'): array
    {
        $uncapped = $opening + $contracted;
        $available = $cap !== null ? min($uncapped, $cap) : $uncapped;
        $forfeited = round($uncapped - $available, 2);

        [$openLabel, $poolLabel, $usedLabel, $closeLabel] = $periodType === 'month'
            ? ['Opening balance for the month', 'Pool for the month', 'Used in the month', 'Opening balance for next month']
            : ['Opening balance', 'Pool for the period', 'Used in the period', 'Closing balance'];

        $lines = [
            '## '.__('Billing summary', [], $locale),
            '',
            __($openLabel, [], $locale).': '.self::signedHours($opening),
            '',
            __($poolLabel, [], $locale).': '.self::signedHours($contracted),
            '',
            __($usedLabel, [], $locale).': '.self::signedHours(-$actual),
            '',
            __($closeLabel, [], $locale).': '.self::signedHours(round($available - $actual, 2)),
        ];

        if ($forfeited > 0) {
            $lines[] = '';
            $lines[] = __('Hours above the agreed carry-over cap, not carried forward: :hours', [
                'hours' => self::formatHours($forfeited),
            ], $locale);
        }

        return $lines;
    }

    /**
     * The billing summary of a composed body, always from code: every billing
     * summary section already in the body (a model may write one, in either
     * language) is removed, and the deterministic one is appended at the end.
     * The figures and wording therefore never depend on what a model wrote.
     */
    public static function enforce(string $body, float $opening, float $contracted, float $actual, ?float $cap, ?string $locale = null, string $periodType = 'month'): string
    {
        $stripped = self::strip($body);

        return mb_rtrim($stripped)."\n\n".implode("\n", self::lines($opening, $contracted, $actual, $cap, $locale, $periodType))."\n";
    }

    /**
     * Remove every h2 billing summary section (any supported locale), each
     * up to the next heading of level 2 or higher. Headings inside code are
     * not headings (MarkdownBlocks). An unparseable body is returned as is.
     */
    public static function strip(string $body): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $body);
        $blocks = MarkdownBlocks::parse($body);
        if ($lines === false || $blocks === null) {
            return $body;
        }

        $drop = [];
        $headings = $blocks->headings;
        foreach ($headings as $index => $heading) {
            if ($heading['level'] !== 2 || self::summaryLocale($lines, $heading) === null) {
                continue;
            }
            $start = $heading['start'] - 1;
            $end = count($lines);
            foreach (array_slice($headings, $index + 1) as $next) {
                if ($next['level'] <= 2) {
                    $end = $next['start'] - 1;

                    break;
                }
            }
            for ($i = $start; $i < $end; $i++) {
                $drop[$i] = true;
            }
        }

        if ($drop === []) {
            return $body;
        }

        $kept = [];
        foreach ($lines as $i => $line) {
            if (! isset($drop[$i])) {
                $kept[] = $line;
            }
        }

        return mb_rtrim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)))."\n";
    }

    /**
     * Replace the billing summary section of a report body, in the language
     * its heading is written in. Headings are the parser's (MarkdownBlocks):
     * ATX or Setext, never inside code. The section runs to the next heading
     * of the same or a higher level, so its own subheadings are replaced with
     * it. With no such section, or more
     * than one, the body is returned unchanged and the status says why.
     *
     * @return array{status: self::REWRITTEN|self::MISSING|self::AMBIGUOUS, body: string}
     */
    public static function replaceIn(string $body, float $opening, float $contracted, float $actual, ?float $cap, string $periodType = 'month'): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $body);
        $blocks = MarkdownBlocks::parse($body);
        if ($lines === false || $blocks === null) {
            return ['status' => self::AMBIGUOUS, 'body' => $body];
        }

        $sections = [];
        foreach ($blocks->headings as $index => $heading) {
            $locale = $heading['level'] === 2 ? self::summaryLocale($lines, $heading) : null;
            if ($locale !== null) {
                $sections[] = [$index, $locale];
            }
        }

        if ($sections === []) {
            return ['status' => self::MISSING, 'body' => $body];
        }
        if (count($sections) > 1) {
            return ['status' => self::AMBIGUOUS, 'body' => $body];
        }

        [[$index, $locale]] = $sections;
        $start = $blocks->headings[$index]['start'] - 1;
        $end = count($lines);
        foreach (array_slice($blocks->headings, $index + 1) as $heading) {
            if ($heading['level'] <= 2) {
                $end = $heading['start'] - 1;

                break;
            }
        }

        $rest = array_slice($lines, $end);
        $section = self::lines($opening, $contracted, $actual, $cap, $locale, $periodType);
        $out = [...array_slice($lines, 0, $start), ...$section, ...($rest === [] ? [] : ['', ...$rest])];

        return ['status' => self::REWRITTEN, 'body' => mb_rtrim(implode("\n", $out))."\n"];
    }

    /**
     * Signed so the direction reads at a glance. Zero carries no sign, and
     * the minus is a real minus sign, not a hyphen.
     */
    public static function signedHours(float $hours): string
    {
        $rounded = round($hours, 2);

        if ($rounded > 0) {
            return '+'.self::formatHours($rounded);
        }

        if ($rounded < 0) {
            return '−'.self::formatHours(abs($rounded));
        }

        return self::formatHours(0.0);
    }

    public static function formatHours(float $hours): string
    {
        $rounded = round($hours, 2);

        // Whole hours print bare, fractions without trailing zeros: 9.5h, 0.25h.
        return $rounded === floor($rounded)
            ? sprintf('%dh', (int) $rounded)
            : mb_rtrim(mb_rtrim(sprintf('%.2f', $rounded), '0'), '.').'h';
    }

    /**
     * The locale whose "Billing summary" this heading says, ATX or Setext.
     *
     * @param  list<string>  $lines
     * @param  array{level: int, start: int, end: int}  $heading
     */
    private static function summaryLocale(array $lines, array $heading): ?string
    {
        // An ATX heading is one line; a Setext heading's last line is its underline.
        $last = $heading['end'] > $heading['start'] ? $heading['end'] - 1 : $heading['end'];
        $text = implode(' ', array_slice($lines, $heading['start'] - 1, $last - $heading['start'] + 1));
        $text = (string) preg_replace('/^[ \t>]*(?:#{1,6}(?=[ \t]|$))?|[ \t]+#+[ \t]*$/u', '', $text);

        foreach (self::LOCALES as $candidate) {
            if (mb_strtolower(mb_trim($text)) === mb_strtolower(__('Billing summary', [], $candidate))) {
                return $candidate;
            }
        }

        return null;
    }
}

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
 */
final class Humanizer
{
    public static function clean(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $out = $text;

        // Invisible artifacts first: zero-width chars, soft hyphen; NBSP → space.
        $out = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{00AD}"], '', $out);
        $out = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $out);

        // Em/en dashes → plain hyphen, preserving the spacing style around it.
        $out = (string) preg_replace('/\s*[—–]\s*/u', ' - ', $out);
        // …except when the dash glued two word-characters (ranges like 9–17):
        // the rule above already spaced it; collapse digit ranges back.
        $out = (string) preg_replace('/(\d) - (\d)/u', '$1-$2', $out);

        // Curly quotes/apostrophes → straight; single-char ellipsis → dots.
        $out = str_replace(["\u{201C}", "\u{201D}", "\u{201E}", "\u{00BB}", "\u{00AB}"], '"', $out);
        $out = str_replace(["\u{2018}", "\u{2019}", "\u{201A}"], "'", $out);
        $out = str_replace("\u{2026}", '...', $out);

        // The dash spacing rule can double spaces at line starts/ends.
        $out = (string) preg_replace('/[ \t]+$/m', '', $out);
        $out = (string) preg_replace('/^[ \t]*- /m', '- ', $out);

        return $out;
    }
}

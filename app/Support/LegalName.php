<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/** Company legal forms ("Sp. z o.o.", "S.A."), one list for the short name on posters and crumbs and for the duplicate check's key. */
final class LegalName
{
    /** Each form as its letters and digits in lower-case ASCII, so every spelling of "sp. z o.o." is "spzoo". */
    public const array FORMS = [
        'spzoo',
        'spolkazoo',
        'spolkazograniczonaodpowiedzialnoscia',
        'sa',
        'spolkaakcyjna',
        'psa',
        'prostaspolkaakcyjna',
        'ska',
        'spolkakomandytowoakcyjna',
        'spk',
        'spolkakomandytowa',
        'spj',
        'spolkajawna',
        'spp',
        'spolkapartnerska',
        'sc',
        'spolkacywilna',
        'gmbh',
        'ag',
        'bv',
        'sro',
        'ltd',
        'limited',
        'llc',
        'inc',
        'corp',
        'plc',
    ];

    /** Written as one bare word these count only in capitals, so a name ending in "Sa" or "Ag" keeps its last word. */
    private const array CAPITALS_WHEN_BARE = ['sa', 'sc', 'ska', 'psa', 'ag', 'bv'];

    /** The name without its trailing legal forms; a name that is only a form stays whole. */
    public static function short(string $name): string
    {
        $full = mb_trim($name);
        $words = self::words($full);
        $kept = count($words);
        $end = mb_strlen($full, '8bit');
        while (($form = self::trailingForm(array_slice($words, 0, $kept), strictCase: true)) > 0 && self::standsAlone($full, $words[$kept - $form]['at'], $end)) {
            $kept -= $form;
            $end = $words[$kept]['at'];
        }
        if ($kept === count($words)) {
            return $full;
        }

        $cut = mb_substr($full, 0, $words[$kept]['at'], '8bit');

        return (string) preg_replace('/[\s,\-\x{00A0}]+$/u', '', $cut);
    }

    /**
     * The name's words (runs of letters and digits) folded to lower-case ASCII, each with where it starts (bytes) and as written.
     *
     * @return list<array{fold: string, at: int, text: string}>
     */
    public static function words(string $name): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches, PREG_OFFSET_CAPTURE);
        $words = [];
        foreach ($matches[0] as [$text, $at]) {
            $fold = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii($text)));
            if ($fold !== '') {
                $words[] = ['fold' => $fold, 'at' => $at, 'text' => $text];
            }
        }

        return $words;
    }

    /**
     * How many of the last words spell one legal form, the longest such run, or 0; the first word is never one.
     *
     * @param  list<array{fold: string, at: int, text: string}>  $words
     * @param  list<string>  $extra  further forms the caller also looks past
     */
    public static function trailingForm(array $words, bool $strictCase = false, array $extra = []): int
    {
        for ($count = count($words) - 1; $count >= 1; $count--) {
            $tail = array_slice($words, -$count);
            $fold = implode('', array_column($tail, 'fold'));
            if (! in_array($fold, self::FORMS, true) && ! in_array($fold, $extra, true)) {
                continue;
            }
            if ($strictCase && $count === 1 && in_array($fold, self::CAPITALS_WHEN_BARE, true) && mb_strtoupper($tail[0]['text']) !== $tail[0]['text']) {
                continue;
            }

            return $count;
        }

        return 0;
    }

    /** A form shown on its own after a space or comma, written with nothing but letters, dots, hyphens and spaces: not "Acme-Ltd" or "Acme (SA)". */
    private static function standsAlone(string $name, int $at, int $end): bool
    {
        $form = (string) preg_replace('/[\s,\x{00A0}]+$/u', '', mb_substr($name, $at, $end - $at, '8bit'));

        return preg_match('/[\s,\x{00A0}]$/u', mb_substr($name, 0, $at, '8bit')) === 1
            && preg_match('/^[\p{L}\p{N}.\-\s\x{00A0}]+$/u', $form) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Client;
use App\Support\LegalName;
use Illuminate\Support\Collection;

/**
 * Finds existing clients whose name is close to a new one, deterministically:
 * both names are folded to a key (lower case, ASCII letters and digits only,
 * LegalName's forms and trade prefixes dropped, spaces removed). Equal keys
 * match, and so do keys one edit apart once both are long enough for a typo
 * to be the likelier story than a different company.
 */
final class ClientNameMatcher
{
    private const int TYPO_MIN_LENGTH = 6;

    /** Endings too loose for a shown short name ("Smith & Co", "Hurtownia Sp.") that still mean the same company here. */
    private const array LOOSE_FORMS = ['sp', 'spolka', 'co', 'zoo'];

    /** Polish trade-form abbreviations dropped from the start of a name, as their folded letters ("P.H.U." is "phu"). */
    private const array PREFIXES = ['phu', 'pphu', 'ppuh', 'fhu', 'fphu', 'puh', 'ph', 'fh', 'przedsiebiorstwo'];

    public static function key(string $name): string
    {
        $words = LegalName::words($name);
        $all = implode('', array_column($words, 'fold'));

        do {
            $before = count($words);
            while (($form = LegalName::trailingForm($words, extra: self::LOOSE_FORMS)) > 0) {
                $words = array_slice($words, 0, -$form);
            }
            $prefix = self::leadingPrefix($words);
            if ($prefix > 0) {
                $words = array_slice($words, $prefix);
            }
        } while (count($words) !== $before);

        $key = implode('', array_column($words, 'fold'));

        return $key !== '' ? $key : $all;
    }

    /**
     * Archived clients count: re-adding one is better done by restoring it.
     *
     * @return Collection<int, Client>
     */
    public function similar(int $accountId, string $name): Collection
    {
        $key = self::key($name);
        if ($key === '') {
            return collect();
        }

        return Client::withTrashed()
            ->where('account_id', $accountId)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'lifecycle_stage', 'deleted_at'])
            ->filter(fn (Client $client): bool => self::close($key, self::key((string) $client->name)))
            ->values();
    }

    /**
     * How many of the first words spell a trade prefix, the longest such run, or 0; the last word is never one.
     *
     * @param  list<array{fold: string, at: int, text: string}>  $words
     */
    private static function leadingPrefix(array $words): int
    {
        for ($count = count($words) - 1; $count >= 1; $count--) {
            if (in_array(implode('', array_column(array_slice($words, 0, $count), 'fold')), self::PREFIXES, true)) {
                return $count;
            }
        }

        return 0;
    }

    private static function close(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return min(mb_strlen($a), mb_strlen($b)) >= self::TYPO_MIN_LENGTH && levenshtein($a, $b) <= 1;
    }
}

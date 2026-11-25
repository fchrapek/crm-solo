<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Every retired URL and where it lives now: the only place the old words may
 * appear (EnglishUrlsGuardTest reads every other file in app/Http).
 */
final class LegacyUrls
{
    /** Old path pattern => new path; the first match wins. */
    public const array PATHS = [
        '#^/jutro$#' => '/tomorrow',
        '#^/tydzien$#' => '/week',
        '#^/zadania$#' => '/all-tasks',
        '#^/dzien/(\d{4}-\d{2}-\d{2})$#' => '/day/$1',
        '#^/czesc$#' => '/waitlist',
        '#^/clients/([^/]+)/przeglad$#' => '/clients/$1/overview',
        '#^/clients/([^/]+)/zadania$#' => '/clients/$1/tasks',
        '#^/clients/([^/]+)/dziennik$#' => '/clients/$1/journal',
        '#^/clients/([^/]+)/rozliczenia$#' => '/clients/$1/billing',
        '#^/clients/([^/]+)/dane$#' => '/clients/$1/details',
        '#^/settings/konto$#' => '/settings/general',
        '#^/settings/wyglad$#' => '/profile',
        '#^/settings/zadania$#' => '/profile',
        '#^/settings/leady$#' => '/settings/leads',
        '#^/settings/pola-klienta$#' => '/settings/general',
        '#^/settings/integracje$#' => '/settings/integrations',
        '#^/settings/formularze$#' => '/settings/forms',
        '#^/settings/formularze/(\d+)$#' => '/settings/forms/$1',
        '#^/settings/agenci$#' => '/settings/integrations',
        '#^/settings/(account|client-fields)$#' => '/settings/general',
        '#^/settings/(appearance|tasks)$#' => '/profile',
        '#^/settings/(sms|email)$#' => '/settings/messages',
        '#^/settings/agents$#' => '/settings/integrations',
        '#^/users(/create|/[^/]+/edit)?$#' => '/settings/general',
        '#^/integrations(/[^/]+)?$#' => '/settings/integrations',
    ];

    /**
     * New path pattern => old query key => [new key, old value => new value].
     * A value missing from its map passes unchanged.
     *
     * @var array<string, array<string, array{string, array<string, string>}>>
     */
    public const array QUERIES = [
        '#^/$#' => ['widok' => ['view', ['dzis' => 'today', 'gotowe' => 'summary']]],
        '#^/week$#' => ['nastepny' => ['next', []]],
        '#^/all-tasks$#' => ['na' => ['for', ['jutro' => 'tomorrow']], 'z' => ['from', ['dzien' => 'day']], 'ukryte' => ['hidden', []]],
        '#^/clients/[^/]+/journal$#' => ['widok' => ['view', ['relacja' => 'relationship']], 'praca' => ['work', []], 'wpisy' => ['notes', []]],
        '#^/clients/[^/]+/tasks$#' => ['wszystkie' => ['expand', []]],
        '#^/clients/[^/]+/projects/[^/]+$#' => ['wszystkie' => ['expand', []]],
        '#^/clients/[^/]+/billing$#' => ['miesiac' => ['month', []], 'miesiace' => ['ledger', []]],
        '#^/clients$#' => ['nowy' => ['new', []]],
        '#^/contacts$#' => ['kontakt' => ['contact', []]],
        '#^/leads$#' => ['widok' => ['view', ['lista' => 'list']]],
        '#^/month-close$#' => ['pokaz' => ['filter', ['ty' => 'you', 'godziny' => 'hours', 'zakonczone' => 'finished']]],
        '#^/revenue$#' => ['kwoty' => ['amounts', ['brutto' => 'gross', 'netto' => 'net']]],
        '#^/tasks/[^/]+$#' => ['historia' => ['history', []], 'zmien' => ['change', []]],
        '#^/#' => ['pomocnik' => ['helper', []]],
    ];

    /** The new relative URL for an old one, or null when nothing in it is old. */
    public function translate(string $path, string $query): ?string
    {
        // A browser reads a Location of //host or /\host as another site, and a control character could split the header.
        if (preg_match('#^/(?![/\\\\])#', $path) !== 1 || preg_match('/[\\\\\x00-\x20\x7f]/', $path.$query) === 1) {
            return null;
        }
        $path = mb_rtrim($path, '/') ?: '/';
        $target = $this->path($path);
        [$pairs, $renamed] = $this->query($target ?? $path, $query);

        if ($target === null && ! $renamed) {
            return null;
        }

        return ($target ?? $path).($pairs === [] ? '' : '?'.implode('&', $pairs));
    }

    private function path(string $path): ?string
    {
        foreach (self::PATHS as $pattern => $replacement) {
            if (preg_match($pattern, $path) === 1) {
                return preg_replace($pattern, $replacement, $path);
            }
        }

        return null;
    }

    /** @return array{list<string>, bool} the raw pairs in their order, and whether a key was renamed */
    private function query(string $path, string $query): array
    {
        $pairs = $query === '' ? [] : explode('&', $query);
        $rules = [];
        foreach (self::QUERIES as $pattern => $keys) {
            if (preg_match($pattern, $path) === 1) {
                $rules += $keys;
            }
        }
        if ($rules === [] || $pairs === []) {
            return [$pairs, false];
        }

        $keys = array_map(fn (string $pair): string => urldecode(explode('=', $pair, 2)[0]), $pairs);
        $renamed = false;
        $out = [];
        foreach ($pairs as $i => $pair) {
            if (! isset($rules[$keys[$i]])) {
                $out[] = $pair;

                continue;
            }
            $renamed = true;
            [$key, $values] = $rules[$keys[$i]];
            if (in_array($key, $keys, true)) {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $out[] = isset($parts[1]) ? $key.'='.($values[urldecode($parts[1])] ?? $parts[1]) : $key;
        }

        return [$out, $renamed];
    }
}

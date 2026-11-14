<?php

declare(strict_types=1);

namespace App\Services\Integrations\Trello;

/**
 * Maps Trello list names to the canonical CRM Solo lane vocabulary.
 *
 * Mapping is keyed on Trello *list ID* (not name) so renaming a list on Trello
 * doesn't break user configuration. This service only handles the auto-detection
 * step — given a list name, guess which canonical lane it represents.
 */
final class TrelloListMapper
{
    public const LANE_BACKLOG = 'Backlog';

    public const LANE_TODO = 'To-Do';

    public const LANE_DOING = 'Doing';

    public const LANE_TESTING = 'Testing';

    public const LANE_DONE = 'Done';

    public const CANONICAL_LANES = [
        self::LANE_BACKLOG,
        self::LANE_TODO,
        self::LANE_DOING,
        self::LANE_TESTING,
        self::LANE_DONE,
    ];

    /** @var array<string, string[]> */
    private const PATTERNS = [
        self::LANE_DONE => ['done', 'completed', 'shipped', 'live', 'released', 'closed', 'archived'],
        self::LANE_DOING => ['doing', 'in progress', 'in_progress', 'wip', 'working'],
        self::LANE_TESTING => ['testing', 'review', 'qa', 'pr review', 'in review'],
        self::LANE_TODO => ['todo', 'to-do', 'to_do', 'to do', 'inbox', 'queue', 'next', 'this week'],
        self::LANE_BACKLOG => ['backlog', 'ideas', 'someday', 'icebox', 'parking lot'],
    ];

    /**
     * Work still in hand: anything but Testing and Done, custom lanes included.
     * A card moved here after the owner finished it has been sent back.
     */
    public static function isActiveLane(string $lane): bool
    {
        return ! in_array($lane, [self::LANE_TESTING, self::LANE_DONE], true);
    }

    /**
     * Guess the canonical lane for a given Trello list name. Returns null when
     * no pattern matches confidently — caller should fall back to LANE_BACKLOG
     * and flag the list for user attention.
     */
    public static function guess(string $trelloListName): ?string
    {
        $normalized = mb_strtolower(mb_trim($trelloListName));

        if ($normalized === '') {
            return null;
        }

        // Done first — it's the highest-priority match. A list named "Done DEV"
        // should hit Done, not get tripped by "DEV" matching some other key.
        // Lanes also intentionally favor longer multi-word matches (in_progress
        // before progress, in review before review).
        foreach (self::PATTERNS as $lane => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle)) {
                    return $lane;
                }
            }
        }

        return null;
    }

    /**
     * Build a default mapping from an array of Trello lists. Lists that don't
     * confidently match any canonical lane fall back to Backlog.
     *
     * @param  array<int, array{id: string, name: string}>  $trelloLists
     * @return array<string, string> trello_list_id => canonical lane
     */
    public static function autoDetectMapping(array $trelloLists): array
    {
        $mapping = [];
        foreach ($trelloLists as $list) {
            if (! isset($list['id'], $list['name'])) {
                continue;
            }
            $mapping[$list['id']] = self::guess($list['name']) ?? self::LANE_BACKLOG;
        }

        return $mapping;
    }

    public static function isCanonicalLane(string $name): bool
    {
        return in_array($name, self::CANONICAL_LANES, true);
    }

    /** A column name as the owner would write it: trimmed, inner spaces collapsed. */
    public static function tidyName(string $name): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_trim($name));
    }

    /** The key two column names share when they are the same column to the owner. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(self::tidyName($name));
    }

    /**
     * Column names tidied, blanks dropped, case-insensitive duplicates kept once (first spelling wins).
     *
     * @param  iterable<mixed>  $names
     * @return list<string>
     */
    public static function uniqueNames(iterable $names): array
    {
        $unique = [];
        foreach ($names as $name) {
            if (! is_string($name) || self::tidyName($name) === '') {
                continue;
            }
            $unique[self::nameKey($name)] ??= self::tidyName($name);
        }

        return array_values($unique);
    }

    /**
     * The ids of a board's lists whose names the owner skips (Ustawienia · Integracje · Trello).
     *
     * @param  array<int, array{id?: string, name?: string}>  $trelloLists
     * @param  array<mixed>  $skippedNames
     * @return list<string>
     */
    public static function skippedListIds(array $trelloLists, array $skippedNames): array
    {
        $skipped = array_map(self::nameKey(...), self::uniqueNames($skippedNames));
        if ($skipped === []) {
            return [];
        }

        return collect($trelloLists)
            ->filter(fn ($list): bool => isset($list['id'], $list['name']) && in_array(self::nameKey((string) $list['name']), $skipped, true))
            ->map(fn (array $list): string => (string) $list['id'])
            ->values()
            ->all();
    }
}

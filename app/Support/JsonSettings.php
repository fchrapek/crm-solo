<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** A settings column written one top-level scalar key at a time in the database, so a concurrent write of another key is never reset. */
final class JsonSettings
{
    private const string KEY = '/^[a-z][a-z0-9_]*$/';

    /** @param  array<string, bool|int|string>  $values */
    public static function write(string $table, int $id, array $values): void
    {
        if ($values === []) {
            return;
        }
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $pairs = [];
        $bindings = [];
        foreach ($values as $key => $value) {
            self::assertKey((string) $key);
            $pairs[] = "'$.{$key}', ".match (true) {
                is_bool($value) => $sqlite ? ($value ? "json('true')" : "json('false')") : ($value ? 'true' : 'false'),
                is_int($value) => (string) $value,
                is_string($value) => '?',
                default => throw new InvalidArgumentException("A setting is a bool, an int or a string; {$key} is not."),
            };
            if (is_string($value)) {
                $bindings[] = $value;
            }
        }

        DB::update(
            'update '.self::table($table)." set settings = json_set(coalesce(settings, '{}'), ".implode(', ', $pairs).'), updated_at = ? where id = ?',
            [...$bindings, now()->toDateTimeString(), $id],
        );
    }

    /** Removes one key, leaving every other key as it stands. */
    public static function forget(string $table, int $id, string $key): void
    {
        self::assertKey($key);

        DB::update('update '.self::table($table)." set settings = json_remove(settings, '$.{$key}') where id = ? and settings is not null", [$id]);
    }

    private static function assertKey(string $key): void
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("A setting key is lower case letters, digits and underscores; \"{$key}\" is not.");
        }
    }

    private static function table(string $table): string
    {
        if (! in_array($table, ['accounts', 'users'], true)) {
            throw new InvalidArgumentException("Settings are written for accounts and users only, not {$table}.");
        }

        return $table;
    }
}

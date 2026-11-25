<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "The column holds this text", with %, _ and the escape character taken literally on MariaDB and SQLite alike:
 * the escape is named in the clause, since SQLite has no default one.
 */
final class LikePattern
{
    private const string ESCAPE = '!';

    public static function contains(string $text): string
    {
        return '%'.str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $text).'%';
    }

    /**
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public static function where(Builder|QueryBuilder $query, string $column, string $text, string $boolean = 'and'): Builder|QueryBuilder
    {
        return $query->whereRaw($query->getGrammar()->wrap($column)." LIKE ? ESCAPE '".self::ESCAPE."'", [self::contains($text)], $boolean);
    }

    /**
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public static function orWhere(Builder|QueryBuilder $query, string $column, string $text): Builder|QueryBuilder
    {
        return self::where($query, $column, $text, 'or');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Shared\Database;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * The one mechanism for text that a person types into a search (CONTRIBUTING "Search").
 *
 * `skola` finds `Škola`, `ŠKOLA` finds `škola`, in any letter case. Both sides of the comparison go through the
 * database function `kokpit_search_text(text)` (lower of unaccent), so the column and the typed term are normalised
 * identically. The column expression is identical to the one of the GIN trigram indexes
 * `{table}_{column}_search_index` of the 2026_10_30 migration, which lets the planner use them. A newly searched
 * column needs its own index in a new migration; without one the search is still correct but scans sequentially.
 *
 * The term is always a bound value. A typed `%`, `_` or `!` is a plain character: each gets the escape character
 * `!` in front of it, the same way Filament escapes its own search patterns.
 *
 * unaccent may expand a few symbols (a ligature becomes two letters). That changes only how a term matches and never
 * which rows the caller's scoped query can reach: this class only adds a predicate to a builder the caller has
 * already scoped.
 */
final class AccentInsensitiveSearch
{
    public const string FUNCTION = 'kokpit_search_text';

    public const string ESCAPE = '!';

    /**
     * Adds `kokpit_search_text(column) like kokpit_search_text(%term%) escape '!'` to the query.
     *
     * The column is quoted by the query grammar, the function name and the escape character are constants, the
     * boolean is limited to and or or, and the pattern is a bound value, so nothing a caller passes can reach the
     * SQL as it is.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     *
     * @throws InvalidArgumentException a boolean other than and or or
     */
    public static function where(EloquentBuilder|QueryBuilder $query, string $column, string $term, string $boolean = 'and'): void
    {
        if ($boolean !== 'and' && $boolean !== 'or') {
            throw new InvalidArgumentException('The boolean must be and or or.');
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        // The column is quoted by the grammar and the rest is constant, so the text is not injectable.
        $query->whereRaw(
            // @phpstan-ignore argument.type (the column is quoted by the grammar, the pattern is bound)
            self::FUNCTION.'('.$base->getGrammar()->wrap($column).') like '.self::FUNCTION."(?) escape '".self::ESCAPE."'",
            [self::pattern($term)],
            $boolean,
        );
    }

    /**
     * The `%term%` pattern with the characters that mean something in a pattern escaped.
     */
    public static function pattern(string $term): string
    {
        $escaped = str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term,
        );

        return '%'.$escaped.'%';
    }
}

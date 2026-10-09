<?php

declare(strict_types=1);

namespace App\Domain\Shared\Database;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * The one mechanism for text that is sorted for people (CONTRIBUTING "Ordering").
 *
 * The database default collation sorts Czech wrongly: `Čočka` lands before `Cihla`
 * and `Chata` before `Cihla`. The ICU collation `cs-CZ-x-icu` puts `č` after `c` and
 * `ch` after `h`. Every list of names or titles that a person reads in alphabetical
 * order goes through `orderBy()` here instead of `orderBy('name')`.
 *
 * The collation must exist in the production database; `kokpit:deploy:verify` checks
 * it, and a missing collation makes the query fail loudly rather than sort wrongly.
 */
final class CzechCollation
{
    public const string NAME = 'cs-CZ-x-icu';

    /**
     * Orders by a text column in Czech order.
     *
     * The column is quoted by the query grammar and the direction is limited to asc
     * or desc, so nothing a caller passes can reach the SQL as it is.
     *
     * @template TBuilder of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     *
     * @throws InvalidArgumentException a direction other than asc or desc
     */
    public static function orderBy(EloquentBuilder|QueryBuilder $query, string $column, string $direction = 'asc'): EloquentBuilder|QueryBuilder
    {
        $direction = strtolower($direction);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException('The sort direction must be asc or desc.');
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        // The column is quoted by the grammar and the collation is a constant, so the text is not injectable.
        $query->orderBy(new Expression($base->getGrammar()->wrap($column).' COLLATE "'.self::NAME.'"'), $direction); // @phpstan-ignore argument.type

        return $query;
    }
}

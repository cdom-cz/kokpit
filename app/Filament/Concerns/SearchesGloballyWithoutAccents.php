<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Shared\Database\AccentInsensitiveSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * For a Filament resource that can be searched globally: the panel global search ignores diacritics and letter case
 * (CONTRIBUTING "Search").
 *
 * Filament builds the predicate of one word and one attribute in `applyGlobalSearchAttributeConstraint()`; this trait
 * replaces it with `AccentInsensitiveSearch::where()`, the one mechanism, so the SQL is identical to the expression of
 * the GIN indexes. Every resource that can be searched globally uses this trait, and
 * `tests/Feature/Search/AccentInsensitiveSearchTest.php` fails when one does not.
 *
 * Filament has already lowercased the term; that is harmless, because the SQL function folds it again. The trait only
 * adds predicates to the query the resource's `getGlobalSearchEloquentQuery()` has already scoped (archive filters,
 * eager loads, the order), so no Partner scope or policy changes. The override mirrors Filament 5.11
 * `HasGlobalSearch::applyGlobalSearchAttributeConstraint()`, so check it on every Filament upgrade.
 */
trait SearchesGloballyWithoutAccents
{
    /**
     * @param  Builder<*>  $query
     * @param  array<string>  $searchAttributes
     * @return Builder<*>
     */
    protected static function applyGlobalSearchAttributeConstraint(Builder $query, string $search, array $searchAttributes, bool &$isFirst): Builder
    {
        foreach ($searchAttributes as $searchAttribute) {
            $whereClause = $isFirst ? 'where' : 'orWhere';

            if (str_contains($searchAttribute, '.')) {
                $relationship = substr($searchAttribute, 0, (int) strrpos($searchAttribute, '.'));
                $column = substr($searchAttribute, (int) strrpos($searchAttribute, '.') + 1);

                $query->{$whereClause.'Has'}(
                    $relationship,
                    static fn (Builder $related): Builder => AccentInsensitiveSearch::where($related, $related->qualifyColumn($column), $search),
                );
            } else {
                AccentInsensitiveSearch::where($query, $query->qualifyColumn($searchAttribute), $search, $whereClause === 'where' ? 'and' : 'or');
            }

            $isFirst = false;
        }

        return $query;
    }
}

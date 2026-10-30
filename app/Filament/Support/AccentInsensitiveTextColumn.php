<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Database\AccentInsensitiveSearch;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * The text column of every table: a searchable column ignores diacritics and letter case (CONTRIBUTING "Search").
 *
 * AppServiceProvider binds `TextColumn` to this class, and Filament's `Column::make()` resolves a column through the
 * container, so every searchable table column of both panels searches through `AccentInsensitiveSearch` with no
 * change at any `->searchable()` call site. The SQL is identical to the expression of the GIN indexes
 * `{table}_{column}_search_index`.
 *
 * Only `applySearchConstraint()` is replaced. The subclass deliberately ignores `forceSearchCaseInsensitive()`, which
 * nothing uses, because the search always folds case. A search closure written at a call site
 * (`searchable(query: ...)`) and a JSON path stay with Filament. The method only adds predicates to the query
 * Filament has already scoped, so the Partner scopes and the policies apply unchanged.
 *
 * The override mirrors `InteractsWithTableQuery::applySearchConstraint()` of Filament 5.11, so check it on every
 * Filament upgrade.
 */
final class AccentInsensitiveTextColumn extends TextColumn
{
    /**
     * @param  EloquentBuilder<*>  $query
     * @return EloquentBuilder<*>
     */
    public function applySearchConstraint(EloquentBuilder $query, string $search, bool &$isFirst): EloquentBuilder
    {
        $model = $query->getModel();
        $searchColumns = $this->getSearchColumns($model);

        if ($this->searchQuery !== null || ! $this->canSearchAsPlainColumns($model, $searchColumns)) {
            return parent::applySearchConstraint($query, $search, $isFirst);
        }

        $relationshipName = $this->hasRelationship($model) ? $this->getRelationshipName($model) : null;

        foreach ($searchColumns as $searchColumn) {
            if ($relationshipName !== null) {
                // The column stays unqualified inside the subquery, as Filament does, so a self-relation resolves to the inner table.
                $constraint = static function (EloquentBuilder $related) use ($searchColumn, $search): void {
                    AccentInsensitiveSearch::where($related, $searchColumn, $search);
                };

                if ($isFirst) {
                    $query->whereHas($relationshipName, $constraint);
                } else {
                    $query->orWhereHas($relationshipName, $constraint);
                }
            } else {
                AccentInsensitiveSearch::where(
                    $query,
                    str_contains($searchColumn, '.') ? $searchColumn : $model->qualifyColumn($searchColumn),
                    $search,
                    $isFirst ? 'and' : 'or',
                );
            }

            $isFirst = false;
        }

        return $query;
    }

    /**
     * Whether every search column is a plain column of the table (or of the related table), not a JSON path.
     *
     * Filament reads a dotted column that does not start with the table name as a JSON path, and `->` as a JSON
     * operator; those go back to Filament.
     *
     * @param  array<string>  $searchColumns
     */
    private function canSearchAsPlainColumns(Model $model, array $searchColumns): bool
    {
        $isRelationship = $this->hasRelationship($model);
        $tablePrefix = $model->getTable().'.';

        foreach ($searchColumns as $searchColumn) {
            if (str_contains($searchColumn, '->')) {
                return false;
            }

            if (! str_contains($searchColumn, '.')) {
                continue;
            }

            if ($isRelationship || ! str_starts_with($searchColumn, $tablePrefix) || str_contains(substr($searchColumn, strlen($tablePrefix)), '.')) {
                return false;
            }
        }

        return true;
    }
}

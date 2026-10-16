<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\ApiAbility;
use App\Http\Requests\Api\V1\ListTransactionCategoriesRequest;
use App\Http\Resources\Api\V1\TransactionCategoryResource;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\UrlParam;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Read-only transaction categories (D-02): the tree an importer needs to find the category UUID to book into.
 *
 * Nothing here writes: creating, renaming, archiving, moving and deleting a category stay in the Admin panel, so
 * there is no POST, PATCH or DELETE route under `transaction-categories`. Route parameters are plain strings, not
 * implicit model bindings, so every miss answers the same Czech 404 message without a model class name.
 */
#[Group('Transaction categories')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left.')]
final class TransactionCategoryController
{
    /**
     * The filters of the list, `filter[<name>]`. The query-builder allow-list, the request rules and the
     * generated documentation all read this one list.
     */
    public const array FILTERS = ['type', 'archived'];

    /**
     * The columns the list can be sorted by; a leading `-` sorts descending.
     */
    public const array SORTS = ['name', 'created_at'];

    /**
     * The tiebreaker of every sort and a key of every cursor of the list.
     */
    public const string ID_COLUMN = 'transaction_categories.id';

    #[Endpoint(
        'List transaction categories',
        'Requires ability `'.ApiAbility::FinanceRead->value.'`. Returns the transaction categories sorted by name, a page at a time; follow `meta.next_cursor` with the `cursor` parameter until it is `null`. Categories form a tree of two levels: `parent_id` is `null` for a root and the UUID of its root for a child. `type` is `income` or `expense` and a transaction must use a category of its own type. `archived` is `true` when the category or its parent is archived; such a category cannot be used for a new transaction, so filter with `filter[archived]=false` to find the UUIDs you can book into. Categories are read-only here: they are created, renamed, archived and ordered in the Admin panel only.',
    )]
    #[QueryParam('filter[type]', 'string', 'Only categories of this type: `income` or `expense`.', required: false, example: 'expense', enum: ['income', 'expense'])]
    #[QueryParam('filter[archived]', 'boolean', 'Only archived categories (`true`: the category or its parent is archived) or only categories that can still be used (`false`).', required: false, example: false)]
    #[QueryParam('cursor', 'string', 'The `meta.next_cursor` of the previous page of this list, sent with the same `sort`. Any other value answers 422.', required: false, example: 'No-example')]
    #[QueryParam('per_page', 'integer', 'Categories per page, 1 to 200. Default 50.', required: false, example: 50)]
    #[QueryParam('sort', 'string', 'Sort by `name` (default, ascending) or `created_at`; a leading `-` sorts descending. Names sort in the plain database order with an id tiebreaker, not in Czech alphabetical order.', required: false, example: 'name', enum: ['name', '-name', 'created_at', '-created_at'])]
    #[Response(TransactionExamples::CATEGORY_LIST, 200, 'A page of transaction categories.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A filter, the page size, the cursor or the sort is not valid.')]
    public function index(ListTransactionCategoriesRequest $request): AnonymousResourceCollection
    {
        $descending = str_starts_with($request->sortExpression(), '-');

        $categories = QueryBuilder::for(TransactionCategory::query()->with('parent'))
            ->allowedFilters(
                AllowedFilter::callback('type', static fn (Builder $query, mixed $value): Builder => $query->where('transaction_categories.type', $value)),
                AllowedFilter::callback('archived', static fn (Builder $query, mixed $value): Builder => self::archived($query, filter_var($value, FILTER_VALIDATE_BOOLEAN))),
            )
            ->allowedSorts(...self::SORTS)
            ->defaultSort('name')
            // The id breaks ties between categories that share the sorted value, in the direction of the sort,
            // so a cursor page never skips or repeats a row.
            ->orderBy(self::ID_COLUMN, $descending ? 'desc' : 'asc')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return TransactionCategoryResource::collection($categories);
    }

    #[Endpoint(
        'Show a transaction category',
        'Requires ability `'.ApiAbility::FinanceRead->value.'`. Returns one transaction category by its UUID, an archived one included. `parent_id` is `null` for a root category; `archived` is `true` when the category or its parent is archived and the category cannot be used for a new transaction.',
    )]
    #[UrlParam('category', 'string', 'Transaction category UUID.', example: '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7')]
    #[Response(TransactionExamples::CATEGORY, 200, 'The category.')]
    #[ResponseField('data.id', 'string', 'UUID of the category.')]
    #[ResponseField('data.parent_id', 'string', 'UUID of the parent category, or null for a root category.', nullable: true)]
    #[ResponseField('data.name', 'string', 'Name of the category.')]
    #[ResponseField('data.type', 'string', 'income or expense.')]
    #[ResponseField('data.archived', 'boolean', 'True when the category or its parent is archived, so it cannot be used for a new transaction.')]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No category has this UUID.')]
    public function show(string $category): TransactionCategoryResource
    {
        $found = TransactionCategory::query()->with('parent')->whereKey($category)->first();

        if ($found === null) {
            abort(404, __('api.errors.not_found'));
        }

        return new TransactionCategoryResource($found);
    }

    /**
     * Narrows to the categories that are archived (own or through the parent) or to those that are not.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function archived(Builder $query, bool $archived): Builder
    {
        $archivedParents = static fn (QueryContract $sub): QueryContract => $sub
            ->select('archived_parents.id')
            ->from('transaction_categories as archived_parents')
            ->whereNotNull('archived_parents.archived_at');

        if ($archived) {
            return $query->where(static fn (Builder $inner): Builder => $inner
                ->whereNotNull('transaction_categories.archived_at')
                ->orWhereIn('transaction_categories.parent_id', $archivedParents));
        }

        return $query
            ->whereNull('transaction_categories.archived_at')
            ->where(static fn (Builder $inner): Builder => $inner
                ->whereNull('transaction_categories.parent_id')
                ->orWhereNotIn('transaction_categories.parent_id', $archivedParents));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\ApiAbility;
use App\Http\Requests\Api\V1\ListTransactionsRequest;
use App\Http\Resources\Api\V1\TransactionResource;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\UrlParam;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Income and expense transactions of the Admin over the REST API.
 *
 * Route parameters are plain strings, not implicit model bindings: every miss answers the same Czech 404
 * message without a model class name, and nothing touches the database while the route is only being described.
 */
#[Group('Transactions')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left.')]
final class TransactionController
{
    /**
     * The filters of the list, `filter[<name>]`. The query-builder allow-list, the request rules and the
     * generated documentation all read this one list.
     */
    public const array FILTERS = ['type', 'category', 'client', 'project', 'date_from', 'date_to', 'currency', 'czk_source'];

    /**
     * The public sort names of the list; a leading `-` sorts descending.
     */
    public const array SORTS = ['transaction_date', 'amount', 'created_at'];

    /**
     * The ORDER BY column of every public sort name. The cursor of a page is keyed by these column names, so the
     * request builds the cursor rule from this map; `amount` orders by the CZK figure so currencies compare.
     */
    public const array SORT_COLUMNS = ['transaction_date' => 'transaction_date', 'amount' => 'czk_minor', 'created_at' => 'created_at'];

    /**
     * The tiebreaker of every sort and a key of every cursor of the list.
     */
    public const string ID_COLUMN = 'transactions.id';

    #[Endpoint(
        'List transactions',
        'Requires ability `'.ApiAbility::FinanceRead->value.'`. Returns the income and expense transactions newest first by transaction date (ties broken by id), a page at a time. Follow `meta.next_cursor` with the `cursor` parameter until it is `null`. Use the filters to check what is already booked before writing a transaction. Every transaction has the same keys as the single transaction; receipts and other files are not included.',
    )]
    #[QueryParam('filter[type]', 'string', 'Only transactions of this type: `income` or `expense`.', required: false, example: 'expense', enum: ['income', 'expense'])]
    #[QueryParam('filter[category]', 'string', 'Only transactions of this category (UUID). A parent category includes the transactions of its child categories; an unknown UUID matches no transaction.', required: false, example: '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7')]
    #[QueryParam('filter[client]', 'string', 'Only transactions of this client (UUID). A client has no key, so only the UUID is accepted.', required: false, example: '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9')]
    #[QueryParam('filter[project]', 'string', 'Only transactions of this project: its UUID or its key, for example `DEMO`. Archived projects are included; an unknown key matches no transaction.', required: false, example: 'DEMO')]
    #[QueryParam('filter[date_from]', 'string', 'Transactions dated on or after this day, `YYYY-MM-DD` (a Europe/Prague calendar day, inclusive).', required: false, example: '2026-10-01')]
    #[QueryParam('filter[date_to]', 'string', 'Transactions dated on or before this day, `YYYY-MM-DD` (a Europe/Prague calendar day, inclusive).', required: false, example: '2026-10-31')]
    #[QueryParam('filter[currency]', 'string', 'Only transactions in this currency, an upper case ISO 4217 code such as `EUR`.', required: false, example: 'EUR')]
    #[QueryParam('filter[czk_source]', 'string', 'Only transactions whose CZK figure was booked this way: `identity`, `cnb`, `manual` or `custom` (an own exchange rate).', required: false, example: 'custom', enum: ['identity', 'cnb', 'manual', 'custom'])]
    #[QueryParam('cursor', 'string', 'The `meta.next_cursor` of the previous page of this list, sent with the same `sort`. Any other value answers 422.', required: false, example: 'No-example')]
    #[QueryParam('per_page', 'integer', 'Transactions per page, 1 to 200. Default 50.', required: false, example: 50)]
    #[QueryParam('sort', 'string', 'Sort by `transaction_date` (default, newest first as `-transaction_date`), `amount` or `created_at`; a leading `-` sorts descending. `amount` orders by the CZK figure `czk_minor`, so transactions in different currencies compare. Ties are broken by id in the direction of the sort.', required: false, example: '-transaction_date', enum: ['transaction_date', '-transaction_date', 'amount', '-amount', 'created_at', '-created_at'])]
    #[Response(TransactionExamples::TRANSACTION_LIST, 200, 'A page of transactions.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A filter, the page size, the cursor or the sort is not valid.')]
    public function index(ListTransactionsRequest $request, ReferenceResolver $refs): AnonymousResourceCollection
    {
        $descending = str_starts_with($request->sortExpression(), '-');

        $transactions = QueryBuilder::for(Transaction::query())
            ->allowedFilters(
                AllowedFilter::callback('type', static fn (Builder $query, mixed $value): Builder => $query->where('transactions.type', $value)),
                // A parent category includes its children, as on the Admin screen; an unknown UUID matches nothing.
                AllowedFilter::callback('category', static fn (Builder $query, mixed $value): Builder => $query->where(
                    static fn (Builder $inner): Builder => $inner
                        ->where('transactions.category_id', $value)
                        ->orWhereIn('transactions.category_id', static fn (QueryContract $sub): QueryContract => $sub
                            ->select('transaction_categories.id')
                            ->from('transaction_categories')
                            ->where('transaction_categories.parent_id', $value)),
                )),
                // Clients have no key: the request checked the UUID.
                AllowedFilter::callback('client', static fn (Builder $query, mixed $value): Builder => $query->where('transactions.client_id', $value)),
                // A key that matches nothing narrows the list to nothing; it is not an error.
                AllowedFilter::callback('project', static function (Builder $query, mixed $value) use ($refs): Builder {
                    $id = is_string($value) ? $refs->projectId($value) : null;

                    return $id === null ? $query->whereRaw('false') : $query->where('transactions.project_id', $id);
                }),
                // Both days are inclusive; the column is a plain date, so no time zone is involved.
                AllowedFilter::callback('date_from', static fn (Builder $query, mixed $value): Builder => $query->where('transactions.transaction_date', '>=', $value)),
                AllowedFilter::callback('date_to', static fn (Builder $query, mixed $value): Builder => $query->where('transactions.transaction_date', '<=', $value)),
                AllowedFilter::callback('currency', static fn (Builder $query, mixed $value): Builder => $query->where('transactions.amount_currency', $value)),
                AllowedFilter::callback('czk_source', static fn (Builder $query, mixed $value): Builder => $query->where('transactions.czk_source', $value)),
            )
            ->allowedSorts(
                AllowedSort::field('transaction_date', self::SORT_COLUMNS['transaction_date']),
                AllowedSort::field('amount', self::SORT_COLUMNS['amount']),
                AllowedSort::field('created_at', self::SORT_COLUMNS['created_at']),
            )
            ->defaultSort('-transaction_date')
            // The id breaks ties between transactions that share the sorted value, in the direction of the sort,
            // so a cursor page never skips or repeats a row.
            ->orderBy(self::ID_COLUMN, $descending ? 'desc' : 'asc')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    #[Endpoint(
        'Show a transaction',
        'Requires ability `'.ApiAbility::FinanceRead->value.'`. Returns one income or expense transaction by its UUID. Amounts are integers in minor units (cents, haléře) next to their ISO currency code. `czk_minor` is the amount in CZK and `czk_source` says how it was booked: `identity` (the transaction is in CZK), `cnb` (converted at the Czech National Bank rate of the transaction date or the last banking day before it), `manual` (a CZK figure typed by the user) or `custom` (converted at an own exchange rate). The rate snapshot `exchange_rate`, `exchange_unit_amount` and `exchange_rate_date` is `null` unless the source is `cnb` or `custom`; the rate is a decimal string for `exchange_unit_amount` units of the currency. `description`, `counterparty`, `document_number`, `client_id` and `project_id` are `null` when the transaction has none. Receipts and other files are not part of the API.',
    )]
    #[UrlParam('id', 'string', 'Transaction UUID.', example: '0199e3b0-1a2b-7c3d-8e4f-a1b2c3d4e5f6')]
    #[Response(TransactionExamples::TRANSACTION, 200, 'A transaction.')]
    #[ResponseField('data.id', 'string', 'UUID of the transaction.')]
    #[ResponseField('data.type', 'string', 'income or expense.')]
    #[ResponseField('data.transaction_date', 'string', 'Date of the transaction, YYYY-MM-DD.')]
    #[ResponseField('data.category_id', 'string', 'UUID of the transaction category.')]
    #[ResponseField('data.amount_minor', 'integer', 'Amount in minor units of the currency, always positive; the type gives the direction.')]
    #[ResponseField('data.currency', 'string', 'ISO 4217 currency code of the amount.')]
    #[ResponseField('data.czk_minor', 'integer', 'Amount in CZK, in haléře.')]
    #[ResponseField('data.czk_source', 'string', 'How the CZK figure was booked: identity, cnb, manual or custom.')]
    #[ResponseField('data.exchange_rate', 'string', 'Exchange rate as a decimal string, or null unless the source is cnb or custom.', nullable: true)]
    #[ResponseField('data.exchange_unit_amount', 'integer', 'Units of the currency the rate is quoted for, or null unless the source is cnb or custom.', nullable: true)]
    #[ResponseField('data.exchange_rate_date', 'string', 'Date of the rate, YYYY-MM-DD, or null unless the source is cnb or custom.', nullable: true)]
    #[ResponseField('data.description', 'string', 'Free text, or null.', nullable: true)]
    #[ResponseField('data.counterparty', 'string', 'The other party of the transaction, or null.', nullable: true)]
    #[ResponseField('data.document_number', 'string', 'Number of the receipt or invoice, or null.', nullable: true)]
    #[ResponseField('data.client_id', 'string', 'UUID of the client the transaction belongs to, or null.', nullable: true)]
    #[ResponseField('data.project_id', 'string', 'UUID of the project the transaction belongs to, or null.', nullable: true)]
    #[ResponseField('data.created_at', 'string', 'Creation instant, ISO 8601 in UTC.')]
    #[ResponseField('data.updated_at', 'string', 'Last change instant, ISO 8601 in UTC.')]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No transaction has this UUID.')]
    public function show(string $transaction): TransactionResource
    {
        return new TransactionResource(self::find($transaction));
    }

    /**
     * The transaction of the UUID, or the Czech 404 of the API.
     */
    private static function find(string $id): Transaction
    {
        $transaction = Transaction::query()->whereKey($id)->first();

        if ($transaction === null) {
            abort(404, __('api.errors.not_found'));
        }

        return $transaction;
    }
}

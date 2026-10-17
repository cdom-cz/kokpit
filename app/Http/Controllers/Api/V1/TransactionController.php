<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Actions\UpdateTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Support\SqlState;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\User;
use App\Http\Requests\Api\V1\ListTransactionsRequest;
use App\Http\Requests\Api\V1\StoreTransactionRequest;
use App\Http\Requests\Api\V1\TransactionPayload;
use App\Http\Requests\Api\V1\UpdateTransactionRequest;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Http\Responses\ApiError;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Header;
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

    /**
     * The write methods of this controller that can answer 409. Deleting a transaction something else still refers
     * to is a conflict; editing one is not, until a later phase locks a transaction that a payment turned into income.
     * The documentation test requires a 409 response on every PATCH and DELETE that is not left out of this list.
     */
    public const array CONFLICT_METHODS = ['DELETE'];

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

    #[Endpoint(
        'Create a transaction',
        'Requires ability `'.ApiAbility::FinanceWrite->value.'`. Records an income or expense through the same rules as the application. Give `type`, `transaction_date`, `category_id`, `amount_minor` (minor units, a positive integer) and `currency`. The category is referenced by its UUID (see the transaction categories), the client by its UUID and the project by its UUID or its key; a name is refused. A client and a project are optional, but a project needs its client and must belong to it. The CZK figure is fixed in one of three ways: send `exchange_rate` (a string; with `exchange_unit_amount` for a rate quoted per several units, default 1) to book at an own rate, send `czk_amount_minor` to book the figure the bank used, or send neither and the Czech National Bank rate of the transaction date (or the last banking day before it) is used. Sending both a rate and a figure answers 422 under `exchange_rate`. A transaction in CZK takes none of the three and answers 422 under the field you sent. A currency without any stored rate list is accepted with a rate or a figure; without them it answers 422 under `currency`. When no rate is stored for the day, the answer is 422 under `czk_amount_minor`: send a rate or the figure. Every 422 is keyed by the field names of this request. The answer is 201 with the transaction and a `Location` header. To retry safely, send an optional `Idempotency-Key` header (1 to 255 visible characters, a fresh value for every new transaction; keys are kept for 24 hours and belong to the token that sent them): the same key with the same body creates the transaction once, and every repeat gets the stored 201 and the identical body again, marked with the response header `Idempotent-Replayed: true` (the replay carries no `Location`). Only successful answers are stored, so a request that failed can be corrected and sent again with the same key. The same key with a different body answers 422 with the code `idempotency_key_reused`, a malformed key 422 with `idempotency_key_invalid`, and a repeat while the first request is still running 409 with `idempotency_in_progress`; wait a moment and send it again.',
    )]
    #[Header('Idempotency-Key', 'demo-key-0003')]
    #[Response(TransactionExamples::TRANSACTION, 201, 'The transaction was created.')]
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
    #[Response(ApiExamples::IDEMPOTENCY_IN_PROGRESS, 409, 'A request with the same Idempotency-Key is still running (code `idempotency_in_progress`).')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A field is not valid or the application refuses the transaction; the errors are keyed by field.')]
    #[Response(ApiExamples::IDEMPOTENCY_KEY_REUSED, 422, 'The Idempotency-Key was used with a different body (code `idempotency_key_reused`), or is malformed (code `idempotency_key_invalid`).')]
    public function store(StoreTransactionRequest $request, RecordTransaction $record, ReferenceResolver $refs): JsonResponse
    {
        try {
            $transaction = $record->handle(self::actor($request), TransactionPayload::forCreate($request->validated(), $refs));
        } catch (ValidationException $e) {
            throw TransactionPayload::remap($e);
        }

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.transactions.show', $transaction->id));
    }

    #[Endpoint(
        'Update a transaction',
        'Requires ability `'.ApiAbility::FinanceWrite->value.'`. Edits a transaction through the same rules as the application. Send only the keys to change: an absent key keeps its value, and `null` clears `description`, `counterparty`, `document_number`, `client_id` and `project_id`. A project needs its client, so clearing the client of a transaction that keeps a project answers 422 under `project_id`. `currency` needs `amount_minor` in the same request, because an amount belongs to one currency; without it the answer is 422 under `amount_minor`. A transaction booked at an own rate keeps that rate when its amount changes: the CZK figure is recomputed at the stored rate. A changed currency drops the own rate and uses the Czech National Bank rate. To change how the CZK figure is fixed, send a new `exchange_rate` (a string) for an own rate or a new `czk_amount_minor` for the figure the bank used. `exchange_rate: null` drops an own rate, `czk_amount_minor: null` drops a bank figure, and both `null` return any transaction to the Czech National Bank rate; a `null` of a field the transaction does not use changes nothing. A transaction in CZK takes no rate and no CZK figure. Every 422 is keyed by the field names of this request.',
    )]
    #[UrlParam('id', 'string', 'Transaction UUID.', example: '0199e3b0-1a2b-7c3d-8e4f-a1b2c3d4e5f6')]
    #[Response(TransactionExamples::TRANSACTION, 200, 'The transaction after the change.')]
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
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A field is not valid or the application refuses the change; the errors are keyed by field.')]
    public function update(string $transaction, UpdateTransactionRequest $request, UpdateTransaction $update, ReferenceResolver $refs): TransactionResource
    {
        $stored = self::find($transaction);

        try {
            $changed = $update->handle(self::actor($request), $stored, TransactionPayload::forUpdate($request->validated(), $stored, $refs));
        } catch (ValidationException $e) {
            throw TransactionPayload::remap($e);
        }

        return new TransactionResource($changed);
    }

    #[Endpoint(
        'Delete a transaction',
        'Requires ability `'.ApiAbility::FinanceWrite->value.'`. Deletes a transaction for good, together with its receipts, and answers 204 without a body. The receipt files are not part of the API and cannot be recovered. A transaction that another record still refers to cannot be deleted: the answer is 409 with the code `transaction_in_use` and nothing is deleted.',
    )]
    #[UrlParam('id', 'string', 'Transaction UUID.', example: '0199e3b0-1a2b-7c3d-8e4f-a1b2c3d4e5f6')]
    #[Response(null, 204, 'The transaction was deleted.')]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No transaction has this UUID.')]
    #[Response(TransactionExamples::TRANSACTION_IN_USE, 409, 'Another record refers to the transaction, so it cannot be deleted (code `transaction_in_use`).')]
    public function destroy(string $transaction, Request $request, DeleteTransaction $delete): JsonResponse|HttpResponse
    {
        $stored = self::find($transaction);

        try {
            $delete->handle(self::actor($request), $stored);
        } catch (QueryException $e) {
            // The database refused the delete: a foreign key or the frozen-row guard. Any other fault, a lost
            // connection or a deadlock for example, is no "in use" and stays a server error.
            if (! in_array(SqlState::of($e), [SqlState::FOREIGN_KEY, SqlState::FROZEN_ROW], true)) {
                throw $e;
            }

            return ApiError::conflict('transaction_in_use', __('api.errors.transaction_in_use'));
        }

        return response()->noContent();
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

    /**
     * The Admin the token belongs to; the token check has already refused every other kind of user.
     */
    private static function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}

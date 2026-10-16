<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\ApiAbility;
use App\Http\Resources\Api\V1\TransactionResource;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\UrlParam;

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

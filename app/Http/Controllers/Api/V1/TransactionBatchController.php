<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\User;
use App\Http\Requests\Api\V1\BatchStoreTransactionRequest;
use App\Http\Requests\Api\V1\RawJsonBody;
use App\Http\Requests\Api\V1\TransactionPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Header;
use Knuckles\Scribe\Attributes\Response;
use Throwable;

/**
 * Batch create of transactions over the REST API (D-04, D-05).
 *
 * Every item goes through the same `TransactionPayload` mapping and the same `RecordTransaction` Action as a
 * single create, each in the Action's own transaction. There is deliberately no outer transaction: an item that
 * fails leaves no row and never touches the items before or after it. A refusal of the item carries its own field
 * errors under the API field names; any other fault of the server is reported to the log and listed under
 * `transaction` with one generic message, so a late fault cannot end the request in a 500 that releases the
 * Idempotency-Key after earlier items were committed (a retry would save them twice).
 */
#[Group('Transactions')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left. A batch counts as one write.')]
final class TransactionBatchController
{
    /**
     * The sample item of the documented `transactions` list.
     */
    private const array EXAMPLE_TRANSACTION = [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7',
        'amount_minor' => 10000,
        'currency' => 'EUR',
        'exchange_rate' => '25.125',
        'description' => 'Example hosting invoice',
        'counterparty' => 'Demo Hosting',
        'external' => ['source' => 'demo-bank', 'id' => 'TX-1001'],
    ];

    #[Endpoint(
        'Create transactions in a batch',
        'Requires ability `'.ApiAbility::FinanceWrite->value.'`. Records up to 500 transactions in one request, each one through the same rules as a single create. Every item takes the fields of a single create. The items are saved one by one, each in its own transaction: an item that fails is skipped and reported, and the items before and after it are still saved. The answer is 201 when every item was saved, 207 when some were saved and some failed, and 422 when none was saved, and lists only the failed items. To avoid duplicates, deduplicate on your side: store your own id in `external` (it comes back with every transaction; the API never skips a duplicate because of it), and list the transactions of the period before you send a batch again under a new key. To retry safely, send an optional `Idempotency-Key` header (1 to 255 visible characters, a fresh value for every new batch; keys are kept for 24 hours and belong to the token that sent them): the same key with the same body saves the batch once, and every repeat gets the stored 201 or 207 and the identical body again, marked with the response header `Idempotent-Replayed: true`. Only successful answers are stored, so a batch that saved nothing can be corrected and sent again with the same key. The same key with a different body answers 422 with the code `idempotency_key_reused`, and a repeat while the first request is still running 409 with `idempotency_in_progress`.',
    )]
    #[Header('Idempotency-Key', 'demo-key-0004')]
    #[BodyParam('transactions', 'object[]', 'The transactions to record: 1 to 500 JSON objects, each with the fields of a single create. A list with no item or with more than 500 items answers 422 and saves nothing.', example: [self::EXAMPLE_TRANSACTION])]
    #[BodyParam('transactions[].type', 'string', 'Direction of the transaction: `income` or `expense`.', example: 'expense')]
    #[BodyParam('transactions[].transaction_date', 'string', 'Day of the transaction, `YYYY-MM-DD`.', example: '2026-10-14')]
    #[BodyParam('transactions[].category_id', 'string', 'UUID of the transaction category. An archived category, or one of the other type, fails the item.', example: '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7')]
    #[BodyParam('transactions[].amount_minor', 'integer', 'Amount in minor units of the currency, a positive integer.', example: 10000)]
    #[BodyParam('transactions[].currency', 'string', 'ISO 4217 currency code of the amount, upper case.', example: 'EUR')]
    #[BodyParam('transactions[].exchange_rate', 'string', 'An own exchange rate: CZK for `exchange_unit_amount` units of the currency, sent as a string. Not together with `czk_amount_minor`; a transaction in CZK takes none.', required: false, example: '25.125')]
    #[BodyParam('transactions[].exchange_unit_amount', 'integer', 'Units of the currency the rate is quoted for, default 1. Needs `exchange_rate`.', required: false, example: 1)]
    #[BodyParam('transactions[].czk_amount_minor', 'integer', 'The amount in CZK in haléře, as the bank booked it. Not together with `exchange_rate`; a transaction in CZK takes none.', required: false, example: 252000)]
    #[BodyParam('transactions[].description', 'string', 'Free text, at most 2000 characters. Optional.', required: false, example: 'Example hosting invoice')]
    #[BodyParam('transactions[].counterparty', 'string', 'The other party of the transaction, at most 255 characters. Optional.', required: false, example: 'Demo Hosting')]
    #[BodyParam('transactions[].document_number', 'string', 'Number of the receipt or invoice, at most 64 characters. Optional.', required: false, example: 'DEMO-2026-001')]
    #[BodyParam('transactions[].client_id', 'string', 'UUID of the client the transaction belongs to. Optional.', required: false, example: '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9')]
    #[BodyParam('transactions[].project_id', 'string', 'The project: its UUID or its key, for example `DEMO`. Optional, but a project needs its client in `client_id`.', required: false, example: 'DEMO')]
    #[BodyParam('transactions[].external', 'object', 'A free-form JSON object for your own bookkeeping, for example the id of the transaction in the bank or tool it was imported from, at most 4096 bytes as compact JSON and nested at most 8 levels deep. `{}` is fine; a list, a string or a number fails the item. It is returned with the transaction and never used to skip duplicates.', required: false, example: ['source' => 'demo-bank', 'id' => 'TX-1001'])]
    #[Response(TransactionExamples::BATCH_ALL_SAVED, 201, 'Every transaction was saved.')]
    #[Response(TransactionExamples::BATCH_MIXED, 207, 'Some transactions were saved and some failed. `errors` lists only the failed items: `index` is the zero-based position in `transactions`, `errors` the messages by field (`transaction` when the item is not a JSON object, or when it could not be saved because of a fault on the server; send such an item again in a new batch).')]
    #[Response(ApiExamples::IDEMPOTENCY_IN_PROGRESS, 409, 'A request with the same Idempotency-Key is still running (code `idempotency_in_progress`).')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'The `transactions` list is missing, empty or longer than 500 items; nothing was saved.')]
    #[Response(TransactionExamples::BATCH_NONE_SAVED, 422, 'No transaction could be saved; `errors` lists every item. The Idempotency-Key is not kept, so the corrected batch can be sent again with it.')]
    #[Response(ApiExamples::IDEMPOTENCY_KEY_REUSED, 422, 'The Idempotency-Key was used with a different body (code `idempotency_key_reused`), or is malformed (code `idempotency_key_invalid`).')]
    public function __invoke(BatchStoreTransactionRequest $request, RecordTransaction $record, ReferenceResolver $refs): JsonResponse
    {
        $actor = self::actor($request);
        $items = $request->validated('transactions');
        // The items decoded as objects tell `{}` from `[]` and are where each `external` is taken from.
        $raw = RawJsonBody::of($request)->transactions ?? [];
        $raw = is_array($raw) ? array_values($raw) : [];
        $saved = 0;
        $errors = [];

        foreach (is_array($items) ? array_values($items) : [] as $index => $item) {
            try {
                $record->handle($actor, TransactionPayload::forBatchItem($item, $raw[$index] ?? null, $refs));
                $saved++;
            } catch (ValidationException $e) {
                $errors[] = ['index' => $index, 'errors' => TransactionPayload::remap($e)->errors()];
            } catch (Throwable $e) {
                // Every failure of an item is an item error. An exception that is not a refusal would end the
                // request in a 500 after the earlier items were committed, EnsureIdempotency would release the
                // key and a retry with it would save those items again. So it is reported to the log, never to
                // the client, and listed under `transaction` with one generic message; the items after it still run.
                report($e);
                $errors[] = ['index' => $index, 'errors' => ['transaction' => [__('api.errors.batch_item_failed')]]];
            }
        }

        $counts = ['saved' => $saved, 'failed' => count($errors), 'errors' => $errors];

        if ($errors === []) {
            return new JsonResponse($counts, 201);
        }

        if ($saved > 0) {
            return new JsonResponse($counts, 207);
        }

        return new JsonResponse(['message' => __('api.errors.batch_none_saved'), ...$counts], 422);
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

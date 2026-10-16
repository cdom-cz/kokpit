<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The validated body of POST /api/v1/transactions.
 *
 * The route middleware has already authorized the token, so `authorize()` only says yes. The rules check
 * shape only; the Action decides everything else (see TransactionPayload).
 */
final class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return TransactionPayload::rules(true);
    }

    /**
     * The field descriptions and examples of the generated documentation (Scribe).
     *
     * @return array<string, array{description: string, example: mixed, type?: string}>
     */
    public function bodyParameters(): array
    {
        return [
            'type' => [
                'description' => 'Direction of the transaction: `income` or `expense`.',
                'example' => 'expense',
            ],
            'transaction_date' => [
                'description' => 'Day of the transaction, `YYYY-MM-DD`.',
                'example' => '2026-10-14',
            ],
            'category_id' => [
                'description' => 'UUID of the transaction category, found with `GET /api/v1/transaction-categories`. A category is referenced by UUID only, never by name. An archived category, or one of the other type, answers 422.',
                'example' => '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7',
            ],
            'amount_minor' => [
                'description' => 'Amount in minor units of the currency, a positive integer: `123450` is 1234.50 for a two-decimal currency.',
                'example' => 10000,
            ],
            'currency' => [
                'description' => 'ISO 4217 currency code of the amount, upper case, for example `CZK` or `EUR`. A currency without any exchange rate list needs `exchange_rate` or `czk_amount_minor`.',
                'example' => 'EUR',
            ],
            'exchange_rate' => [
                'description' => 'An own exchange rate for this transaction: CZK for `exchange_unit_amount` units of the currency, a positive decimal with at most 10 digits before and 10 after the decimal point. Send the rate as a string, for example `"25.125"`. Do not combine it with `czk_amount_minor`. A transaction in CZK takes no rate.',
                'type' => 'string',
                'example' => '25.125',
            ],
            'exchange_unit_amount' => [
                'description' => 'Units of the currency the rate is quoted for, 1 to 2147483647, default 1 (for example `100` for a rate quoted per 100 units). Needs `exchange_rate`.',
                'example' => 1,
            ],
            'czk_amount_minor' => [
                'description' => 'The amount in CZK in haléře, as the bank booked it (a manual figure). Zero or more. Do not combine it with `exchange_rate`. A transaction in CZK takes no CZK figure. When neither this nor `exchange_rate` is sent, the Czech National Bank rate of the transaction date (or the last banking day before it) is used.',
                'example' => 252000,
            ],
            'description' => [
                'description' => 'Free text, at most 2000 characters. Optional.',
                'example' => 'Example hosting invoice',
            ],
            'counterparty' => [
                'description' => 'The other party of the transaction, at most 255 characters. Optional.',
                'example' => 'Demo Hosting',
            ],
            'document_number' => [
                'description' => 'Number of the receipt or invoice, at most 64 characters. Optional.',
                'example' => 'DEMO-2026-001',
            ],
            'client_id' => [
                'description' => 'UUID of the client the transaction belongs to. A client has no key, so only the UUID is accepted. Optional.',
                'example' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            ],
            'project_id' => [
                'description' => 'The project: its UUID or its key, for example `DEMO`. A name is refused. Optional, but a project needs its client in `client_id` and must belong to it.',
                'example' => 'DEMO',
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The validated body of PATCH /api/v1/transactions/{transaction}.
 *
 * Every key is optional: an absent key keeps the stored value and a null clears the optional ones. The rules check
 * shape only; the Action decides everything else (see TransactionPayload).
 */
final class UpdateTransactionRequest extends FormRequest
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
        return TransactionPayload::rules(false);
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
                'description' => 'Direction of the transaction: `income` or `expense`. A new type needs a category of that type.',
                'example' => 'expense',
            ],
            'transaction_date' => [
                'description' => 'Day of the transaction, `YYYY-MM-DD`.',
                'example' => '2026-10-15',
            ],
            'category_id' => [
                'description' => 'UUID of the transaction category. A category is referenced by UUID only, never by name. A category the transaction already has stays acceptable after it was archived.',
                'example' => '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7',
            ],
            'amount_minor' => [
                'description' => 'Amount in minor units of the currency, a positive integer. Required when `currency` is sent, because an amount belongs to one currency. At an own exchange rate the CZK figure is recomputed at the stored rate.',
                'example' => 12000,
            ],
            'currency' => [
                'description' => 'ISO 4217 currency code, upper case. Needs `amount_minor`. A changed currency drops an own rate and uses the Czech National Bank rate, unless a new `exchange_rate` or `czk_amount_minor` is sent.',
                'example' => 'EUR',
            ],
            'exchange_rate' => [
                'description' => 'A new own exchange rate, sent as a string. `null` drops an own rate: a transaction booked at an own rate returns to the Czech National Bank rate, a bank figure is kept unless `czk_amount_minor` is also `null`. A transaction in CZK takes no rate.',
                'type' => 'string',
                'example' => '25.125',
            ],
            'exchange_unit_amount' => [
                'description' => 'Units of the currency the rate is quoted for, 1 to 2147483647. Needs `exchange_rate`.',
                'example' => 1,
            ],
            'czk_amount_minor' => [
                'description' => 'A new CZK figure in haléře, as the bank booked it. `null` drops a bank figure, so the transaction returns to the Czech National Bank rate. Send `exchange_rate` and `czk_amount_minor` both as `null` to return any transaction to the Czech National Bank rate.',
                'example' => 252000,
            ],
            'description' => [
                'description' => 'Free text, at most 2000 characters. `null` clears it.',
                'example' => 'Example hosting invoice',
            ],
            'counterparty' => [
                'description' => 'The other party of the transaction, at most 255 characters. `null` clears it.',
                'example' => 'Demo Hosting',
            ],
            'document_number' => [
                'description' => 'Number of the receipt or invoice, at most 64 characters. `null` clears it.',
                'example' => 'DEMO-2026-001',
            ],
            'client_id' => [
                'description' => 'UUID of the client. `null` clears it; a project cannot stay without its client, so clear `project_id` in the same request.',
                'example' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            ],
            'project_id' => [
                'description' => 'The project: its UUID or its key, for example `DEMO`. `null` clears it. The project must belong to the client.',
                'example' => 'DEMO',
            ],
        ];
    }
}

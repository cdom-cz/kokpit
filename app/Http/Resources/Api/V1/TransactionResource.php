<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Finance\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON of one transaction (D-07).
 *
 * Every key is listed explicitly and none of the model is passed through, so a column added to the table
 * later never reaches the API by accident. Amounts are integers in minor units next to their currency;
 * `czk_minor` is the CZK figure and `czk_source` says where it came from (identity, cnb, manual or custom).
 * The exchange rate columns are the snapshot of the conversion and are null unless the source is `cnb` or
 * `custom`; the rate is a decimal string, never a float. Dates are `Y-m-d`, instants are ISO 8601 in UTC.
 * Receipts and files are not part of the API.
 *
 * @property-read Transaction $resource
 */
final class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transaction = $this->resource;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'category_id' => $transaction->category_id,
            'amount_minor' => $transaction->amount_minor,
            'currency' => $transaction->amount_currency,
            'czk_minor' => $transaction->czk_minor,
            'czk_source' => $transaction->czk_source->value,
            'exchange_rate' => $transaction->exchange_rate,
            'exchange_unit_amount' => $transaction->exchange_unit_amount,
            'exchange_rate_date' => $transaction->exchange_rate_date?->toDateString(),
            'description' => $transaction->description,
            'counterparty' => $transaction->counterparty,
            'document_number' => $transaction->document_number,
            'client_id' => $transaction->client_id,
            'project_id' => $transaction->project_id,
            'created_at' => self::instant($transaction->created_at),
            'updated_at' => self::instant($transaction->updated_at),
        ];
    }

    private static function instant(?CarbonInterface $instant): ?string
    {
        return $instant?->utc()->toIso8601String();
    }
}

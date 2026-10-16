<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

/**
 * Fixed example payloads of the transaction endpoints for the generated API documentation.
 *
 * Like {@see ApiExamples}, the documentation page is public, so no example may come from a response call or a
 * database row: every `#[Response]` attribute names one of these constants. All values are fictional (identifiers
 * with hex letters in every group; the client and project are the ones the time entry examples use), and the
 * contract test compares TRANSACTION with the keys of the real resource, so an example cannot drift from the API.
 */
final class TransactionExamples
{
    /**
     * A EUR expense booked at an own exchange rate: 100.00 EUR at 25.125 is 2 512.50 CZK.
     */
    public const array TRANSACTION = [
        'data' => [
            'id' => '0199e3b0-1a2b-7c3d-8e4f-a1b2c3d4e5f6',
            'type' => 'expense',
            'transaction_date' => '2026-10-14',
            'category_id' => '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7',
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'czk_minor' => 251250,
            'czk_source' => 'custom',
            'exchange_rate' => '25.1250000000',
            'exchange_unit_amount' => 1,
            'exchange_rate_date' => '2026-10-14',
            'description' => 'Example hosting invoice',
            'counterparty' => 'Demo Hosting',
            'document_number' => 'DEMO-2026-001',
            'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            'project_id' => '0199e39f-d8a1-7c3e-b2f5-e5f6a7b8c9d0',
            'created_at' => '2026-10-14T09:12:00+00:00',
            'updated_at' => '2026-10-14T09:12:00+00:00',
        ],
    ];
}

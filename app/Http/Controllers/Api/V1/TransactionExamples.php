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

    /**
     * A page of the list: two transactions newest first, with the cursor of the next page. The cursor is the one the
     * paginator issues after the second item: its date as the paginator writes a date cast (`Y-m-d 00:00:00`) and its
     * id, so the example cursor is accepted by the API and cannot drift from the real one (see the contract test).
     */
    public const array TRANSACTION_LIST = [
        'data' => [
            self::TRANSACTION['data'],
            [
                'id' => '0199e3b1-3c4d-7e5f-8a61-c3d4e5f6a7b8',
                'type' => 'income',
                'transaction_date' => '2026-10-13',
                'category_id' => '0199e3b0-3c4d-7e5f-a061-c3d4e5f6a7b8',
                'amount_minor' => 1500000,
                'currency' => 'CZK',
                'czk_minor' => 1500000,
                'czk_source' => 'identity',
                'exchange_rate' => null,
                'exchange_unit_amount' => null,
                'exchange_rate_date' => null,
                'description' => 'Example consulting payment',
                'counterparty' => 'Demo Customer',
                'document_number' => null,
                'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
                'project_id' => null,
                'created_at' => '2026-10-13T15:30:00+00:00',
                'updated_at' => '2026-10-13T15:30:00+00:00',
            ],
        ],
        'links' => [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => 'https://kokpit.example.com/api/v1/transactions?per_page=2&cursor=eyJ0cmFuc2FjdGlvbl9kYXRlIjoiMjAyNi0xMC0xMyAwMDowMDowMCIsInRyYW5zYWN0aW9ucy5pZCI6IjAxOTllM2IxLTNjNGQtN2U1Zi04YTYxLWMzZDRlNWY2YTdiOCIsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0',
        ],
        'meta' => [
            'path' => 'https://kokpit.example.com/api/v1/transactions',
            'per_page' => 2,
            'next_cursor' => 'eyJ0cmFuc2FjdGlvbl9kYXRlIjoiMjAyNi0xMC0xMyAwMDowMDowMCIsInRyYW5zYWN0aW9ucy5pZCI6IjAxOTllM2IxLTNjNGQtN2U1Zi04YTYxLWMzZDRlNWY2YTdiOCIsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0',
            'prev_cursor' => null,
        ],
    ];

    /**
     * A child category, the one the TRANSACTION example is booked into.
     */
    public const array CATEGORY = [
        'data' => [
            'id' => '0199e3b0-2b3c-7d4e-9f50-b2c3d4e5f6a7',
            'parent_id' => '0199e3b0-1f2a-7b3c-8d4e-a1b2c3d4e5f6',
            'name' => 'Example hosting',
            'type' => 'expense',
            'archived' => false,
        ],
    ];

    /**
     * A page of categories sorted by name: a root and its child, with the cursor of the next page. The cursor is the
     * one the paginator issues after the second item (its name and its id), so it is accepted by the API.
     */
    public const array CATEGORY_LIST = [
        'data' => [
            [
                'id' => '0199e3b0-1f2a-7b3c-8d4e-a1b2c3d4e5f6',
                'parent_id' => null,
                'name' => 'Example expenses',
                'type' => 'expense',
                'archived' => false,
            ],
            self::CATEGORY['data'],
        ],
        'links' => [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => 'https://kokpit.example.com/api/v1/transaction-categories?per_page=2&cursor=eyJuYW1lIjoiRXhhbXBsZSBob3N0aW5nIiwidHJhbnNhY3Rpb25fY2F0ZWdvcmllcy5pZCI6IjAxOTllM2IwLTJiM2MtN2Q0ZS05ZjUwLWIyYzNkNGU1ZjZhNyIsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0',
        ],
        'meta' => [
            'path' => 'https://kokpit.example.com/api/v1/transaction-categories',
            'per_page' => 2,
            'next_cursor' => 'eyJuYW1lIjoiRXhhbXBsZSBob3N0aW5nIiwidHJhbnNhY3Rpb25fY2F0ZWdvcmllcy5pZCI6IjAxOTllM2IwLTJiM2MtN2Q0ZS05ZjUwLWIyYzNkNGU1ZjZhNyIsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0',
            'prev_cursor' => null,
        ],
    ];

    /**
     * The answer to deleting a transaction that another record still refers to.
     */
    public const array TRANSACTION_IN_USE = [
        'message' => 'Transakci nelze smazat, protože na ni odkazuje jiný záznam.',
        'code' => 'transaction_in_use',
    ];
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The transaction keys and JSON types of the API response (D-07, with `id` first as the clarification under D-07
 * in 09.1-CONTEXT.md records).
 *
 * Every key that can be absent in the domain (the rate snapshot, the free text, the client and the project) may be
 * null in a response. The rate is a decimal string, never a float, and the amounts are integers in minor units.
 * `external` is a decoded JSON object, which is a PHP array.
 */
final class TransactionContract
{
    /**
     * The confirmed keys in their confirmed order with their JSON type; a `?` prefix means the value may be null.
     */
    public const array KEYS = [
        'id' => 'string',
        'type' => 'string',
        'transaction_date' => 'string',
        'category_id' => 'string',
        'amount_minor' => 'integer',
        'currency' => 'string',
        'czk_minor' => 'integer',
        'czk_source' => 'string',
        'exchange_rate' => '?string',
        'exchange_unit_amount' => '?integer',
        'exchange_rate_date' => '?string',
        'description' => '?string',
        'counterparty' => '?string',
        'document_number' => '?string',
        'client_id' => '?string',
        'project_id' => '?string',
        'external' => '?array',
        'created_at' => 'string',
        'updated_at' => 'string',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertShape(array $data): void
    {
        expect(array_keys($data))->toBe(array_keys(self::KEYS));

        foreach (self::KEYS as $key => $type) {
            $allowed = str_starts_with($type, '?') ? [ltrim($type, '?'), 'NULL'] : [$type];

            Assert::assertContains(gettype($data[$key]), $allowed, "type of key $key");
        }
    }
}

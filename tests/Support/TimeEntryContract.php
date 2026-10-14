<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The time entry keys and JSON types the owner confirmed (07-02 Task 2, amended).
 *
 * `project_key` and `task_reference` are output-only. Every key that can be absent in the domain
 * (project, task, description, end, duration, billing time, external) may be null in a response.
 * `external` (D-15, plan 07-06) is a JSON object, which a decoded response holds as an array.
 */
final class TimeEntryContract
{
    /**
     * The confirmed keys in their confirmed order with their JSON type; a `?` prefix means the value may be null.
     */
    public const array KEYS = [
        'id' => 'string',
        'client_id' => 'string',
        'project_id' => '?string',
        'project_key' => '?string',
        'task_id' => '?string',
        'task_reference' => '?string',
        'description' => '?string',
        'started_at' => 'string',
        'ended_at' => '?string',
        'duration_seconds' => '?integer',
        'billable' => 'boolean',
        'billing_state' => 'string',
        'billed_at' => '?string',
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

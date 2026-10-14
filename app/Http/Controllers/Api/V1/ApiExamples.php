<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

/**
 * Fixed example payloads for the generated API documentation.
 *
 * The documentation page is public, so no example may come from a response call or a database row:
 * every `#[Response]` attribute names one of these constants. All values are fictional (project key
 * DEMO, task DEMO-1, identifiers with hex letters in every group), and the contract test compares
 * TIME_ENTRY with the keys of the real resource, so an example cannot drift from the API.
 */
final class ApiExamples
{
    public const array TIME_ENTRY = [
        'data' => [
            'id' => '0199e3a0-5b7c-7d2e-8f41-a1b2c3d4e5f6',
            'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            'project_id' => '0199e39f-d8a1-7c3e-b2f5-e5f6a7b8c9d0',
            'project_key' => 'DEMO',
            'task_id' => '0199e39f-f1b2-7e4a-a3c6-f6a7b8c9d0e1',
            'task_reference' => 'DEMO-1',
            'description' => 'Example work on the DEMO project',
            'started_at' => '2026-10-12T08:00:00+00:00',
            'ended_at' => '2026-10-12T09:30:00+00:00',
            'duration_seconds' => 5400,
            'billable' => true,
            'billing_state' => 'unbilled',
            'billed_at' => null,
            'external' => ['source' => 'demo-tracker', 'id' => 'A-1001'],
            'created_at' => '2026-10-12T09:31:12+00:00',
            'updated_at' => '2026-10-12T09:31:12+00:00',
        ],
    ];

    /**
     * A running entry of a timer started with only a client: no project, task, description, end or duration.
     */
    public const array TIME_ENTRY_RUNNING = [
        'data' => [
            'id' => '0199e3a1-6c8d-7e3f-9a52-b2c3d4e5f6a7',
            'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            'project_id' => null,
            'project_key' => null,
            'task_id' => null,
            'task_reference' => null,
            'description' => null,
            'started_at' => '2026-10-12T10:00:00+00:00',
            'ended_at' => null,
            'duration_seconds' => null,
            'billable' => true,
            'billing_state' => 'unbilled',
            'billed_at' => null,
            'external' => null,
            'created_at' => '2026-10-12T10:00:00+00:00',
            'updated_at' => '2026-10-12T10:00:00+00:00',
        ],
    ];

    /**
     * A page of the list: two entries newest first, with the cursor of the next page.
     */
    public const array TIME_ENTRY_LIST = [
        'data' => [
            [
                'id' => '0199e3a0-5b7c-7d2e-8f41-a1b2c3d4e5f6',
                'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
                'project_id' => '0199e39f-d8a1-7c3e-b2f5-e5f6a7b8c9d0',
                'project_key' => 'DEMO',
                'task_id' => '0199e39f-f1b2-7e4a-a3c6-f6a7b8c9d0e1',
                'task_reference' => 'DEMO-1',
                'description' => 'Example work on the DEMO project',
                'started_at' => '2026-10-12T08:00:00+00:00',
                'ended_at' => '2026-10-12T09:30:00+00:00',
                'duration_seconds' => 5400,
                'billable' => true,
                'billing_state' => 'unbilled',
                'billed_at' => null,
                'external' => ['source' => 'demo-tracker', 'id' => 'A-1001'],
                'created_at' => '2026-10-12T09:31:12+00:00',
                'updated_at' => '2026-10-12T09:31:12+00:00',
            ],
            [
                'id' => '0199e3a1-6c8d-7e3f-9a52-b2c3d4e5f6a7',
                'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
                'project_id' => null,
                'project_key' => null,
                'task_id' => null,
                'task_reference' => null,
                'description' => null,
                'started_at' => '2026-10-11T10:00:00+00:00',
                'ended_at' => '2026-10-11T10:45:00+00:00',
                'duration_seconds' => 2700,
                'billable' => true,
                'billing_state' => 'unbilled',
                'billed_at' => null,
                'external' => null,
                'created_at' => '2026-10-11T10:46:00+00:00',
                'updated_at' => '2026-10-11T10:46:00+00:00',
            ],
        ],
        'links' => [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => 'https://kokpit.example.com/api/v1/time-entries?per_page=2&cursor=eyJ0aW1lX2VudHJpZXMuaWQiOiIwMTk5ZTNhMS02YzhkIiwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ',
        ],
        'meta' => [
            'path' => 'https://kokpit.example.com/api/v1/time-entries',
            'per_page' => 2,
            'next_cursor' => 'eyJ0aW1lX2VudHJpZXMuaWQiOiIwMTk5ZTNhMS02YzhkIiwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ',
            'prev_cursor' => null,
        ],
    ];

    public const array VALIDATION_ERROR = [
        'message' => 'The filter.client field must be a valid UUID.',
        'errors' => [
            'filter.client' => ['The filter.client field must be a valid UUID.'],
        ],
    ];

    /**
     * The answer of a batch in which every entry was saved (201).
     */
    public const array BATCH_ALL_SAVED = [
        'saved' => 3,
        'failed' => 0,
        'errors' => [],
    ];

    /**
     * The answer of a batch in which the second of three entries failed (207).
     */
    public const array BATCH_MIXED = [
        'saved' => 2,
        'failed' => 1,
        'errors' => [
            [
                'index' => 1,
                'errors' => [
                    'ended_at' => ['Konec musí být později než začátek.'],
                ],
            ],
        ],
    ];

    /**
     * The answer of a batch in which no entry could be saved (422).
     */
    public const array BATCH_NONE_SAVED = [
        'message' => 'Nepodařilo se uložit žádnou položku.',
        'saved' => 0,
        'failed' => 2,
        'errors' => [
            [
                'index' => 0,
                'errors' => [
                    'started_at' => ['Zadejte platné datum a čas.'],
                ],
            ],
            [
                'index' => 1,
                'errors' => [
                    'entry' => ['Položka musí být objekt JSON.'],
                ],
            ],
        ],
    ];

    public const array ENTRY_LOCKED = [
        'message' => 'Záznam je vyfakturovaný a nelze ho upravit. Nejdřív zrušte fakturaci.',
        'code' => 'entry_locked',
    ];

    public const array ENTRY_RUNNING = [
        'message' => 'Běžící záznam nelze smazat. Nejdřív ho v aplikaci zastavte.',
        'code' => 'entry_running',
    ];

    public const array IDEMPOTENCY_IN_PROGRESS = [
        'message' => 'Požadavek s tímto klíčem Idempotency-Key se právě zpracovává. Zkuste to za chvíli znovu.',
        'code' => 'idempotency_in_progress',
    ];

    public const array IDEMPOTENCY_KEY_REUSED = [
        'message' => 'Tento klíč Idempotency-Key už byl použit pro jiný požadavek.',
        'code' => 'idempotency_key_reused',
    ];

    public const array UNAUTHENTICATED = [
        'message' => 'Unauthenticated.',
    ];

    public const array FORBIDDEN = [
        'message' => 'Invalid ability provided.',
    ];

    public const array NOT_FOUND = [
        'message' => 'Záznam neexistuje.',
    ];

    public const array TOO_MANY_REQUESTS = [
        'message' => 'Too Many Attempts.',
    ];
}

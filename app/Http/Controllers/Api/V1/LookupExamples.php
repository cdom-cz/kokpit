<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

/**
 * Fixed example payloads of the lookup endpoints for the generated API documentation.
 *
 * Like {@see ApiExamples}, every value is fictional and constant: the public documentation never comes from a
 * response call or a database row. The identifiers are the ones the time entry examples already use, so an
 * integrator can follow one client, project and task through the whole page.
 */
final class LookupExamples
{
    public const array CLIENT = [
        'data' => [
            'id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            'name' => 'Fiktivní klient',
        ],
    ];

    public const array PROJECT = [
        'data' => [
            'id' => '0199e39f-d8a1-7c3e-b2f5-e5f6a7b8c9d0',
            'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            'key' => 'DEMO',
            'name' => 'Ukázkový projekt',
            'status' => 'in_progress',
            'priority' => 'normal',
        ],
    ];

    public const array TASK = [
        'data' => [
            'id' => '0199e39f-f1b2-7e4a-a3c6-f6a7b8c9d0e1',
            'project_id' => '0199e39f-d8a1-7c3e-b2f5-e5f6a7b8c9d0',
            'reference' => 'DEMO-1',
            'title' => 'Ukázkový úkol',
            'status' => 'planned',
            'priority' => 'high',
        ],
    ];

    /**
     * A page of clients, newest first; the last page, so there is no next cursor.
     */
    public const array CLIENT_LIST = [
        'data' => [
            [
                'id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
                'name' => 'Fiktivní klient',
            ],
            [
                'id' => '0199e39e-a1b2-7c3d-8e4f-a5b6c7d8e9f0',
                'name' => 'Ukázková firma',
            ],
        ],
        'links' => [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => null,
        ],
        'meta' => [
            'path' => 'https://kokpit.example.com/api/v1/clients',
            'per_page' => 50,
            'next_cursor' => null,
            'prev_cursor' => null,
        ],
    ];

    /**
     * A page of projects, newest first; the last page.
     */
    public const array PROJECT_LIST = [
        'data' => [
            [
                'id' => '0199e39f-d8a1-7c3e-b2f5-e5f6a7b8c9d0',
                'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
                'key' => 'DEMO',
                'name' => 'Ukázkový projekt',
                'status' => 'in_progress',
                'priority' => 'normal',
            ],
            [
                'id' => '0199e39e-b2c3-7d4e-9f50-b6c7d8e9f0a1',
                'client_id' => '0199e39e-a1b2-7c3d-8e4f-a5b6c7d8e9f0',
                'key' => 'SAMP',
                'name' => 'Druhý ukázkový projekt',
                'status' => 'planned',
                'priority' => 'low',
            ],
        ],
        'links' => [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => null,
        ],
        'meta' => [
            'path' => 'https://kokpit.example.com/api/v1/projects',
            'per_page' => 50,
            'next_cursor' => null,
            'prev_cursor' => null,
        ],
    ];

    /**
     * A page of tasks, newest first; the last page.
     */
    public const array TASK_LIST = [
        'data' => [
            [
                'id' => '0199e39f-f1b2-7e4a-a3c6-f6a7b8c9d0e1',
                'project_id' => '0199e39f-d8a1-7c3e-b2f5-e5f6a7b8c9d0',
                'reference' => 'DEMO-1',
                'title' => 'Ukázkový úkol',
                'status' => 'planned',
                'priority' => 'high',
            ],
            [
                'id' => '0199e39e-c3d4-7e5f-a061-c7d8e9f0a1b2',
                'project_id' => '0199e39e-b2c3-7d4e-9f50-b6c7d8e9f0a1',
                'reference' => 'SAMP-1',
                'title' => 'Druhý ukázkový úkol',
                'status' => 'in_progress',
                'priority' => 'normal',
            ],
        ],
        'links' => [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => null,
        ],
        'meta' => [
            'path' => 'https://kokpit.example.com/api/v1/tasks',
            'per_page' => 50,
            'next_cursor' => null,
            'prev_cursor' => null,
        ],
    ];
}

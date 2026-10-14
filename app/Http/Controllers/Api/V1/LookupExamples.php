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
}

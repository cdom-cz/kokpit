<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

/**
 * Fixed example payload of the contact birthday endpoint for the generated API documentation.
 *
 * Like {@see LookupExamples}, every value is fictional and constant: the public documentation never comes from a
 * response call or a database row. The client is the one the lookup examples use.
 */
final class ContactExamples
{
    /**
     * The contacts with a birthday on 14 October 2026; one contact whose birth year is known.
     */
    public const array BIRTHDAYS = [
        'data' => [
            [
                'id' => '0199e3a0-1a2b-7c3d-8e4f-a5b6c7d8e9f0',
                'client_id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
                'client_name' => 'Fiktivní klient',
                'name' => 'Jana Ukázková',
                'email' => 'jana@example.com',
                'phone' => null,
                'position' => 'Jednatelka',
                'is_primary' => true,
                'is_billing' => false,
                'birth_day' => 14,
                'birth_month' => 10,
                'birth_year' => 1985,
                'age' => 41,
                'created_at' => '2026-10-01T08:00:00+00:00',
                'updated_at' => '2026-10-01T08:00:00+00:00',
            ],
        ],
        'meta' => [
            'date' => '2026-10-14',
        ],
    ];
}

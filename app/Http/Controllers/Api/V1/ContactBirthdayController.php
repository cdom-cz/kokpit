<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Clients\Models\Contact;
use App\Domain\Clients\Queries\ContactBirthdays;
use App\Http\Requests\Api\V1\ListBirthdaysRequest;
use App\Http\Resources\Api\V1\ContactResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;

/**
 * The contacts whose birthday falls on a day (CL-02): the same list as the "Narozeniny dnes" card of the dashboard,
 * read through the same {@see ContactBirthdays} reader, so the card and the API cannot disagree.
 *
 * One calendar day of one company's contacts is a small bounded set, so the whole day is answered at once, without
 * pagination. Contacts are personal data, so the route needs the `contacts:read` ability, which no time or finance
 * token carries.
 */
#[Group('Contacts')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left.')]
final class ContactBirthdayController
{
    #[Endpoint(
        'List today\'s birthdays',
        'Requires ability `contacts:read`. Returns all contacts whose birthday falls on the day, in one answer without pagination, ordered by name. Contacts of archived clients are left out. Each contact carries all its data and the `age` turned in the year of the day, which is `null` when the birth year is not known. A 29 February birthday is listed on 28 February in a non-leap year. The day is today in Europe/Prague unless `date` asks for another one; `meta.date` is the day answered.',
    )]
    #[QueryParam('date', 'string', 'The day to list, as `YYYY-MM-DD` between 1900-01-01 and 2100-12-31. Default: today in Europe/Prague. Any other form answers 422.', required: false, example: '2026-10-14')]
    #[Response(ContactExamples::BIRTHDAYS, 200, 'The contacts with a birthday on the day.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'The date is not a valid day in the form YYYY-MM-DD.')]
    public function __invoke(ListBirthdaysRequest $request, ContactBirthdays $birthdays): AnonymousResourceCollection
    {
        $day = $request->day($birthdays);

        return ContactResource::collection(
            $birthdays->on($day)->map(static fn (Contact $contact): ContactResource => new ContactResource($contact, $day)),
        )->additional(['meta' => ['date' => $day->toDateString()]]);
    }
}

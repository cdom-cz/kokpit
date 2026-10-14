<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\ApiAbility;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Http\Resources\Api\V1\TimeEntryResource;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\UrlParam;

/**
 * Time entries of the Admin over the REST API.
 *
 * Route parameters are plain strings, not implicit model bindings: every miss
 * answers the same Czech 404 message without a model class name, and nothing
 * touches the database while the route is only being described.
 */
#[Group('Time entries')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left.')]
final class TimeEntryController
{
    #[Endpoint(
        'Show a time entry',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns one time entry by its UUID. The entry carries no rate, price or amount.',
    )]
    #[UrlParam('timeEntry', 'string', 'Time entry UUID.', example: '0199e3a0-5b7c-7d2e-8f41-a1b2c3d4e5f6')]
    #[Response(ApiExamples::TIME_ENTRY, 200)]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No time entry has this UUID.')]
    public function show(string $timeEntry): TimeEntryResource
    {
        $entry = TimeEntry::query()->with(['project', 'task'])->whereKey($timeEntry)->first();

        if ($entry === null) {
            abort(404, __('api.errors.not_found'));
        }

        return new TimeEntryResource($entry);
    }
}

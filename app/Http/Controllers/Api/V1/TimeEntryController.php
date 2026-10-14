<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\ApiAbility;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Http\Requests\Api\V1\ListTimeEntriesRequest;
use App\Http\Resources\Api\V1\TimeEntryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\UrlParam;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

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
    /**
     * The filters of the list, `filter[<name>]`. The query-builder allow-list, the request rules and the
     * generated documentation all read this one list.
     */
    public const array FILTERS = ['client'];

    /**
     * The columns the list can be sorted by; a leading `-` sorts descending.
     */
    public const array SORTS = ['started_at', 'created_at'];

    #[Endpoint(
        'List time entries',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns the time entries newest first (by start), a page at a time. Follow `meta.next_cursor` with the `cursor` parameter until it is `null`. The entries carry no rate, price or amount.',
    )]
    #[QueryParam('filter[client]', 'string', 'Only entries of this client (UUID).', required: false, example: '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9')]
    #[QueryParam('cursor', 'string', 'The `meta.next_cursor` of the previous page.', required: false, example: 'No-example')]
    #[QueryParam('per_page', 'integer', 'Entries per page, 1 to 200. Default 50.', required: false, example: 50)]
    #[QueryParam('sort', 'string', 'Sort by `started_at` (default, newest first as `-started_at`) or `created_at`; a leading `-` sorts descending.', required: false, example: '-started_at', enum: ['started_at', '-started_at', 'created_at', '-created_at'])]
    #[Response(ApiExamples::TIME_ENTRY_LIST, 200, 'A page of time entries.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A filter, the page size, the cursor or the sort is not valid.')]
    public function index(ListTimeEntriesRequest $request): AnonymousResourceCollection
    {
        $descending = str_starts_with($request->sortExpression(), '-');

        $entries = QueryBuilder::for(TimeEntry::query()->with(['project', 'task']))
            ->allowedFilters(
                AllowedFilter::callback('client', static fn (Builder $query, mixed $value): Builder => $query->where('time_entries.client_id', $value)),
            )
            ->allowedSorts(...self::SORTS)
            ->defaultSort('-started_at')
            // The id breaks ties between entries that share a start, in the direction of the sort,
            // so a cursor page never skips or repeats a row.
            ->orderBy('time_entries.id', $descending ? 'desc' : 'asc')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return TimeEntryResource::collection($entries);
    }

    #[Endpoint(
        'Show a time entry',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns one time entry by its UUID. The entry carries no rate, price or amount. `project_key` and `task_reference` are read-only. A running entry returns `null` for `ended_at` and `duration_seconds`; `project_id`, `project_key`, `task_id`, `task_reference`, `description` and `billed_at` are `null` when the entry has none.',
    )]
    #[UrlParam('timeEntry', 'string', 'Time entry UUID.', example: '0199e3a0-5b7c-7d2e-8f41-a1b2c3d4e5f6')]
    #[Response(ApiExamples::TIME_ENTRY, 200, 'A finished entry.')]
    #[Response(ApiExamples::TIME_ENTRY_RUNNING, 200, 'A running entry: no end, no duration and no project or task.')]
    #[ResponseField('data.id', 'string', 'UUID of the time entry.')]
    #[ResponseField('data.client_id', 'string', 'UUID of the client.')]
    #[ResponseField('data.started_at', 'string', 'Start instant, ISO 8601 in UTC.')]
    #[ResponseField('data.billable', 'boolean', 'Whether the entry is billable.')]
    #[ResponseField('data.billing_state', 'string', 'unbilled or billed.')]
    #[ResponseField('data.created_at', 'string', 'Creation instant, ISO 8601 in UTC.')]
    #[ResponseField('data.updated_at', 'string', 'Last change instant, ISO 8601 in UTC.')]
    #[ResponseField('data.project_id', 'string', 'UUID of the project, or null.', nullable: true)]
    #[ResponseField('data.project_key', 'string', 'Key of the project (read-only), or null.', nullable: true)]
    #[ResponseField('data.task_id', 'string', 'UUID of the task, or null.', nullable: true)]
    #[ResponseField('data.task_reference', 'string', 'Reference of the task, such as DEMO-1 (read-only), or null.', nullable: true)]
    #[ResponseField('data.description', 'string', 'Free text, or null.', nullable: true)]
    #[ResponseField('data.ended_at', 'string', 'End instant, ISO 8601; null while the entry runs.', nullable: true)]
    #[ResponseField('data.duration_seconds', 'integer', 'Whole seconds from start to end; null while the entry runs.', nullable: true)]
    #[ResponseField('data.billed_at', 'string', 'Instant the entry was billed, ISO 8601, or null while unbilled.', nullable: true)]
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\CreateTimeEntry;
use App\Domain\TimeTracking\Actions\DeleteTimeEntry;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\TimeEntryInput;
use App\Http\Requests\Api\V1\ListTimeEntriesRequest;
use App\Http\Requests\Api\V1\StoreTimeEntryRequest;
use App\Http\Requests\Api\V1\TimeEntryPayload;
use App\Http\Requests\Api\V1\UpdateTimeEntryRequest;
use App\Http\Resources\Api\V1\TimeEntryResource;
use App\Http\Responses\ApiError;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Header;
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
    public const array FILTERS = ['client', 'project', 'task', 'billable', 'billed', 'from', 'to'];

    /**
     * The columns the list can be sorted by; a leading `-` sorts descending.
     */
    public const array SORTS = ['started_at', 'created_at'];

    /**
     * The tiebreaker of every sort and a key of every cursor of the list.
     */
    public const string ID_COLUMN = 'time_entries.id';

    #[Endpoint(
        'List time entries',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns the time entries newest first (by start), a page at a time. Follow `meta.next_cursor` with the `cursor` parameter until it is `null`. The entries carry no rate, price or amount.',
    )]
    #[QueryParam('filter[client]', 'string', 'Only entries of this client (UUID).', required: false, example: '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9')]
    #[QueryParam('filter[project]', 'string', 'Only entries of this project: its UUID or its key, for example `DEMO`. Archived projects are included; an unknown key matches no entry.', required: false, example: 'DEMO')]
    #[QueryParam('filter[task]', 'string', 'Only entries of this task: its UUID or its reference, for example `DEMO-1`. Archived tasks are included; an unknown reference matches no entry.', required: false, example: 'DEMO-1')]
    #[QueryParam('filter[billable]', 'boolean', 'Only billable (`true`) or only non-billable (`false`) entries.', required: false, example: true)]
    #[QueryParam('filter[billed]', 'boolean', 'Only billed (`true`) or only unbilled (`false`) entries.', required: false, example: false)]
    #[QueryParam('filter[from]', 'string', 'Entries that started at or after this moment. A date such as `2026-10-12` is a Europe/Prague calendar day and means the start of that day; an ISO 8601 instant with an offset or `Z` (encode `+` as `%2B`) is used exactly.', required: false, example: '2026-10-12')]
    #[QueryParam('filter[to]', 'string', 'Entries that started at or before this moment. A date such as `2026-10-12` is a Europe/Prague calendar day and includes the whole day; an ISO 8601 instant with an offset or `Z` (encode `+` as `%2B`) is inclusive.', required: false, example: '2026-10-18')]
    #[QueryParam('cursor', 'string', 'The `meta.next_cursor` of the previous page of this list, sent with the same `sort`. Any other value answers 422.', required: false, example: 'No-example')]
    #[QueryParam('per_page', 'integer', 'Entries per page, 1 to 200. Default 50.', required: false, example: 50)]
    #[QueryParam('sort', 'string', 'Sort by `started_at` (default, newest first as `-started_at`) or `created_at`; a leading `-` sorts descending.', required: false, example: '-started_at', enum: ['started_at', '-started_at', 'created_at', '-created_at'])]
    #[Response(ApiExamples::TIME_ENTRY_LIST, 200, 'A page of time entries.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A filter, the page size, the cursor or the sort is not valid.')]
    public function index(ListTimeEntriesRequest $request, ReferenceResolver $refs): AnonymousResourceCollection
    {
        $descending = str_starts_with($request->sortExpression(), '-');

        $entries = QueryBuilder::for(TimeEntry::query()->with(['project', 'task']))
            ->allowedFilters(
                AllowedFilter::callback('client', static fn (Builder $query, mixed $value): Builder => $query->where('time_entries.client_id', $value)),
                // A key that matches nothing narrows the list to nothing; it is not an error.
                AllowedFilter::callback('project', static function (Builder $query, mixed $value) use ($refs): Builder {
                    $id = is_string($value) ? $refs->projectId($value) : null;

                    return $id === null ? $query->whereRaw('false') : $query->where('time_entries.project_id', $id);
                }),
                AllowedFilter::callback('task', static function (Builder $query, mixed $value) use ($refs): Builder {
                    $id = is_string($value) ? $refs->taskId($value) : null;

                    return $id === null ? $query->whereRaw('false') : $query->where('time_entries.task_id', $id);
                }),
                AllowedFilter::callback('billable', static fn (Builder $query, mixed $value): Builder => $query->where('time_entries.billable', self::flag($value))),
                AllowedFilter::callback('billed', static fn (Builder $query, mixed $value): Builder => $query->where(
                    'time_entries.billing_state',
                    self::flag($value) ? BillingState::Billed->value : BillingState::Unbilled->value,
                )),
                AllowedFilter::callback('from', static fn (Builder $query, mixed $value): Builder => self::startedFrom($query, $value)),
                AllowedFilter::callback('to', static fn (Builder $query, mixed $value): Builder => self::startedUntil($query, $value)),
            )
            ->allowedSorts(...self::SORTS)
            ->defaultSort('-started_at')
            // The id breaks ties between entries that share a start, in the direction of the sort,
            // so a cursor page never skips or repeats a row.
            ->orderBy(self::ID_COLUMN, $descending ? 'desc' : 'asc')
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
    #[ResponseField('data.external', 'object', 'The free-form JSON object an import helper stored with the entry, or null.', nullable: true)]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No time entry has this UUID.')]
    public function show(string $timeEntry): TimeEntryResource
    {
        return new TimeEntryResource(self::find($timeEntry));
    }

    #[Endpoint(
        'Create a time entry',
        'Requires ability `'.ApiAbility::TimeWrite->value.'`. Records a finished entry through the same rules as the application. Give `started_at` and exactly one of `ended_at` and `duration_seconds` (whole seconds from the start). Instants are ISO 8601 with an offset or `Z`; an instant without one is refused. The project and the task are referenced by UUID or by key (`DEMO`, `DEMO-1`), never by name; a task fixes its project and client, so `client_id` may then be left out. An entry that disagrees about client, project and task, ends before it starts or names an archived task answers 422 under the field the application marks. Overlapping entries are allowed. `billable` defaults to the default of the task. The answer is 201 with the entry and a `Location` header. To retry safely, send an optional `Idempotency-Key` header (1 to 255 visible characters, a fresh value for every new entry; keys are kept for 24 hours and belong to the token that sent them): the same key with the same body creates the entry once, and every repeat gets the stored 201 and the identical body again, marked with the response header `Idempotent-Replayed: true` (the replay carries no `Location`). Only successful answers are stored, so a request that failed can be corrected and sent again with the same key. The same key with a different body answers 422 with the code `idempotency_key_reused`, a malformed key 422 with `idempotency_key_invalid`, and a repeat while the first request is still running 409 with `idempotency_in_progress`; wait a moment and send it again.',
    )]
    #[Header('Idempotency-Key', 'demo-key-0001')]
    #[Response(ApiExamples::TIME_ENTRY, 201, 'The entry was created.')]
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
    #[ResponseField('data.external', 'object', 'The free-form JSON object an import helper stored with the entry, or null.', nullable: true)]
    #[Response(ApiExamples::IDEMPOTENCY_IN_PROGRESS, 409, 'A request with the same Idempotency-Key is still running (code `idempotency_in_progress`).')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A field is not valid or the application refuses the entry; the errors are keyed by field.')]
    #[Response(ApiExamples::IDEMPOTENCY_KEY_REUSED, 422, 'The Idempotency-Key was used with a different body (code `idempotency_key_reused`), or is malformed (code `idempotency_key_invalid`).')]
    public function store(StoreTimeEntryRequest $request, CreateTimeEntry $create, ReferenceResolver $refs): JsonResponse
    {
        $entry = $create->handle(self::actor($request), TimeEntryPayload::forCreate($request->validated(), $refs, TimeEntryPayload::rawBody($request)));
        $entry->load(['project', 'task']);

        return (new TimeEntryResource($entry))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.time-entries.show', $entry->id));
    }

    #[Endpoint(
        'Update a time entry',
        'Requires ability `'.ApiAbility::TimeWrite->value.'`. Edits an unbilled entry through the same rules as the application. Send only the keys to change: an absent key keeps its value, `null` clears the project or the task (clearing the task keeps the project). `duration_seconds` sets the end to the stored (or the sent) start plus that many seconds. A billed entry, or an entry reserved by an invoice draft, cannot be changed (only its description and external object) and answers 409 with the code `entry_locked`. A running entry (a timer started in the application) keeps its end: sending `ended_at` or `duration_seconds` answers 422 under `ended_at`, while the description, the billable flag, the context and the start can still be changed.',
    )]
    #[UrlParam('timeEntry', 'string', 'Time entry UUID.', example: '0199e3a0-5b7c-7d2e-8f41-a1b2c3d4e5f6')]
    #[Response(ApiExamples::TIME_ENTRY, 200, 'The entry after the change.')]
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
    #[ResponseField('data.external', 'object', 'The free-form JSON object an import helper stored with the entry, or null.', nullable: true)]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No time entry has this UUID.')]
    #[Response(ApiExamples::ENTRY_LOCKED, 409, 'The entry is billed or reserved by an invoice draft and cannot be changed (code `entry_locked`).')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A field is not valid or the application refuses the change; the errors are keyed by field.')]
    public function update(string $timeEntry, UpdateTimeEntryRequest $request, UpdateTimeEntry $update, ReferenceResolver $refs): JsonResponse|TimeEntryResource
    {
        $entry = self::find($timeEntry);
        $validated = $request->validated();

        // A running entry is ended by the timer in the application, never from outside (OD-2). The guard
        // lives here so the Action and the panel keep their behaviour.
        if ($entry->isRunning() && (array_key_exists('ended_at', $validated) || array_key_exists('duration_seconds', $validated))) {
            throw ValidationException::withMessages(['ended_at' => __('api.errors.running_entry_end')]);
        }

        $data = TimeEntryPayload::forUpdate($validated, $entry, $refs, TimeEntryPayload::rawBody($request));

        try {
            $changed = $update->handle(self::actor($request), $entry, $data);
        } catch (DomainException $e) {
            return ApiError::conflict('entry_locked', $e->getMessage());
        }

        return new TimeEntryResource($changed->load(['project', 'task']));
    }

    #[Endpoint(
        'Delete a time entry',
        'Requires ability `'.ApiAbility::TimeWrite->value.'`. Deletes an unbilled, finished entry and answers 204 without a body. A billed entry, or an entry reserved by an invoice draft, answers 409 with the code `entry_locked`; a running entry (a timer started in the application) answers 409 with the code `entry_running`: stop it in the application first.',
    )]
    #[UrlParam('timeEntry', 'string', 'Time entry UUID.', example: '0199e3a0-5b7c-7d2e-8f41-a1b2c3d4e5f6')]
    #[Response(null, 204, 'The entry was deleted.')]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No time entry has this UUID.')]
    #[Response(ApiExamples::ENTRY_LOCKED, 409, 'The entry is billed or reserved by an invoice draft (code `entry_locked`).')]
    #[Response(ApiExamples::ENTRY_RUNNING, 409, 'The entry is running (code `entry_running`).')]
    public function destroy(string $timeEntry, Request $request, DeleteTimeEntry $delete): JsonResponse|HttpResponse
    {
        $entry = self::find($timeEntry);

        if ($entry->isRunning()) {
            return ApiError::conflict('entry_running', __('api.errors.running_entry_delete'));
        }

        try {
            $delete->handle(self::actor($request), $entry);
        } catch (DomainException $e) {
            return ApiError::conflict('entry_locked', $e->getMessage());
        }

        return response()->noContent();
    }

    /**
     * The entry of the UUID, or the Czech 404 of the API.
     */
    private static function find(string $id): TimeEntry
    {
        $entry = TimeEntry::query()->with(['project', 'task'])->whereKey($id)->first();

        if ($entry === null) {
            abort(404, __('api.errors.not_found'));
        }

        return $entry;
    }

    /**
     * The Admin the token belongs to; the token check has already refused every other kind of user.
     */
    private static function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * A true or false filter value: the query-builder hands over `true` / `false` as booleans and `1` / `0` as strings.
     */
    private static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Started at or after the midnight that begins a Prague day, or at or after an instant (OD-5).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function startedFrom(Builder $query, mixed $value): Builder
    {
        if (! is_string($value)) {
            return $query;
        }

        $day = PragueDay::day($value);
        $from = $day === null ? TimeEntryInput::instant($value, 'filter.from') : PragueDay::start($day);

        return $from === null
            ? $query
            : $query->whereRaw('time_entries.started_at >= ?::timestamptz', [$from->format('Y-m-d H:i:sP')]);
    }

    /**
     * Started before the midnight that follows a Prague day (the named day is inclusive), or at or
     * before an instant (OD-5). An entry belongs to the day it started.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function startedUntil(Builder $query, mixed $value): Builder
    {
        if (! is_string($value)) {
            return $query;
        }

        $day = PragueDay::day($value);

        if ($day !== null) {
            return $query->whereRaw('time_entries.started_at < ?::timestamptz', [PragueDay::endExclusive($day)->format('Y-m-d H:i:sP')]);
        }

        $until = TimeEntryInput::instant($value, 'filter.to');

        return $until === null
            ? $query
            : $query->whereRaw('time_entries.started_at <= ?::timestamptz', [$until->format('Y-m-d H:i:sP')]);
    }
}

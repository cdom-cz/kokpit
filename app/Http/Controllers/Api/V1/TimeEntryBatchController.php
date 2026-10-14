<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\CreateTimeEntry;
use App\Http\Requests\Api\V1\BatchStoreTimeEntryRequest;
use App\Http\Requests\Api\V1\TimeEntryPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Header;
use Knuckles\Scribe\Attributes\Response;
use Throwable;

/**
 * Batch create of time entries over the REST API (D-14).
 *
 * Every item goes through the same `TimeEntryPayload` mapping and the same `CreateTimeEntry` Action as a
 * single create, each in the Action's own transaction. There is deliberately no outer transaction: an item
 * that fails leaves no row and never touches the items before or after it. A refusal of the item carries its
 * own field errors; any other fault of the server is reported to the log and listed under `entry` with one
 * generic message, so a late fault cannot end the request in a 500 that releases the Idempotency-Key after
 * earlier items were committed (a retry would save them twice).
 */
#[Group('Time entries')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left. A batch counts as one write.')]
final class TimeEntryBatchController
{
    /**
     * The sample item of the documented `entries` list.
     */
    private const array EXAMPLE_ENTRY = [
        'task_id' => 'DEMO-1',
        'started_at' => '2026-10-12T10:00:00+02:00',
        'duration_seconds' => 5400,
        'description' => 'Example work on the DEMO project',
    ];

    #[Endpoint(
        'Create time entries in a batch',
        'Requires ability `'.ApiAbility::TimeWrite->value.'`. Records up to 100 finished entries in one request, each one through the same rules as a single create. Every item takes the fields of a single create. The items are saved one by one, each in its own transaction: an item that fails is skipped and reported, and the items before and after it are still saved. The answer is 201 when every item was saved, 207 when some were saved and some failed, and 422 when none was saved, and lists only the failed items.',
    )]
    #[Header('Idempotency-Key', 'demo-key-0002')]
    #[BodyParam('entries', 'object[]', 'The time entries to record: 1 to 100 JSON objects, each with the fields of a single create. A list with no item or with more than 100 items answers 422 and saves nothing.', example: [self::EXAMPLE_ENTRY])]
    #[BodyParam('entries[].client_id', 'string', 'UUID of the client. Required unless the project or the task is given, because they fix the client.', required: false, example: '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9')]
    #[BodyParam('entries[].project_id', 'string', 'The project: its UUID or its key, for example `DEMO`. A name is refused. Optional; a task fixes the project.', required: false, example: 'DEMO')]
    #[BodyParam('entries[].task_id', 'string', 'The task: its UUID or its reference, for example `DEMO-1`. A name is refused. Optional. An archived task fails the item.', required: false, example: 'DEMO-1')]
    #[BodyParam('entries[].started_at', 'string', 'Start instant, ISO 8601 with an offset or `Z`, for example `2026-10-12T10:00:00+02:00`. An instant without an offset is refused.', example: '2026-10-12T10:00:00+02:00')]
    #[BodyParam('entries[].ended_at', 'string', 'End instant in the same form, strictly after the start. Give exactly one of `ended_at` and `duration_seconds`.', required: false, example: '2026-10-12T11:30:00+02:00')]
    #[BodyParam('entries[].duration_seconds', 'integer', 'Whole seconds from the start, 1 to 2147483647, instead of `ended_at`. Give exactly one of the two.', required: false, example: 5400)]
    #[BodyParam('entries[].description', 'string', 'Free text, at most 1000 characters. Optional.', required: false, example: 'Example work on the DEMO project')]
    #[BodyParam('entries[].billable', 'boolean', 'Whether the entry is billable. When absent or null, the default of the task applies.', required: false, example: true)]
    #[BodyParam('entries[].external', 'object', 'A free-form JSON object for your own bookkeeping, at most 4096 bytes as compact JSON and nested at most 8 levels deep. `{}` is fine; a list, a string or a number fails the item. It is returned with the entry and never used to skip duplicates.', required: false, example: ['source' => 'demo-tracker', 'id' => 'A-1001'])]
    #[Response(ApiExamples::BATCH_ALL_SAVED, 201, 'Every entry was saved.')]
    #[Response(ApiExamples::BATCH_MIXED, 207, 'Some entries were saved and some failed. `errors` lists only the failed items: `index` is the zero-based position in `entries`, `errors` the messages by field (`entry` when the item is not a JSON object, or when it could not be saved because of a fault on the server; send such an item again in a new batch).')]
    #[Response(ApiExamples::IDEMPOTENCY_IN_PROGRESS, 409, 'A request with the same Idempotency-Key is still running (code `idempotency_in_progress`).')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'The `entries` list is missing, empty or longer than 100 items; nothing was saved.')]
    #[Response(ApiExamples::BATCH_NONE_SAVED, 422, 'No entry could be saved; `errors` lists every item. The Idempotency-Key is not kept, so the corrected batch can be sent again with it.')]
    #[Response(ApiExamples::IDEMPOTENCY_KEY_REUSED, 422, 'The Idempotency-Key was used with a different body (code `idempotency_key_reused`), or is malformed (code `idempotency_key_invalid`).')]
    public function __invoke(BatchStoreTimeEntryRequest $request, CreateTimeEntry $create, ReferenceResolver $refs): JsonResponse
    {
        $actor = self::actor($request);
        $items = $request->validated('entries');
        $raw = TimeEntryPayload::rawBody($request)->entries ?? [];
        $raw = is_array($raw) ? $raw : [];
        $saved = 0;
        $errors = [];

        foreach (is_array($items) ? array_values($items) : [] as $index => $item) {
            try {
                $create->handle($actor, TimeEntryPayload::forBatchItem($item, $raw[$index] ?? null, $refs));
                $saved++;
            } catch (ValidationException $e) {
                $errors[] = ['index' => $index, 'errors' => $e->errors()];
            } catch (Throwable $e) {
                // Every failure of an item is an item error. An exception that is not a refusal would end the
                // request in a 500 after the earlier items were committed, EnsureIdempotency would release the
                // key and a retry with it would save those items again. So it is reported to the log, never to
                // the client, and listed under `entry` with one generic message; the items after it still run.
                report($e);
                $errors[] = ['index' => $index, 'errors' => ['entry' => [__('api.errors.batch_item_failed')]]];
            }
        }

        $counts = ['saved' => $saved, 'failed' => count($errors), 'errors' => $errors];

        if ($errors === []) {
            return new JsonResponse($counts, 201);
        }

        if ($saved > 0) {
            return new JsonResponse($counts, 207);
        }

        return new JsonResponse(['message' => __('api.errors.batch_none_saved'), ...$counts], 422);
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
}

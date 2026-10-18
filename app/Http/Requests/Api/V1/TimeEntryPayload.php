<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The write payload of a time entry: the shape rules and the mapping to the data of the domain Actions.
 *
 * The rules check shape only (types, uuid or key syntax, an ISO 8601 instant with an offset, exactly one
 * of `ended_at` and `duration_seconds`, the integer bound owned by `TimeEntryInput`). Every business rule,
 * the context agreement, the end after the start and the billed lock, stays in the Actions, so the API refuses what the panel
 * refuses with the same field keys and messages. The mapping builds only the allow-listed Action keys:
 * a smuggled `user_id`, `billing_state`, `billed_at` or `id` never leaves this class.
 *
 * `duration_seconds` is an alternative to `ended_at` that exists only here: it becomes
 * `ended_at = started_at + duration` before the Action, which takes `ended_at` only.
 *
 * `external` (D-15) is taken from the raw request body: a PHP array cannot tell the JSON object `{}` from
 * the list `[]`, and Laravel decodes the body to arrays. The body is decoded once more as objects
 * (`rawBody()`, which delegates to `RawJsonBody::of()`), and the `external` member is handed to the Action
 * as it is. The key reaches the Action exactly when the body names it, so on update an absent key keeps
 * the stored value and `null` clears it.
 */
final class TimeEntryPayload
{
    /**
     * An instant with an offset or `Z`. The year 0000 does not exist in PostgreSQL, so it is refused here
     * instead of crashing the insert.
     */
    public const string INSTANT = '/^(?!0000)\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * The JSON body of the request decoded as objects, once per request; null when the body is not a JSON
     * object (a form post, an empty or a malformed body).
     */
    public static function rawBody(Request $request): ?stdClass
    {
        return RawJsonBody::of($request);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $create): array
    {
        return [
            'client_id' => ['nullable', self::uuid('client_required')],
            'project_id' => ['nullable', self::reference(ReferenceResolver::PROJECT_KEY, 'project_unavailable')],
            'task_id' => ['nullable', self::reference(ReferenceResolver::TASK_REFERENCE, 'task_unavailable')],
            'started_at' => [$create ? 'required' : 'sometimes', self::instant()],
            'ended_at' => $create
                ? ['nullable', 'required_without:duration_seconds', 'prohibits:duration_seconds', self::instant()]
                : ['nullable', 'prohibits:duration_seconds', self::instant()],
            'duration_seconds' => ['integer', 'min:1', 'max:'.TimeEntryInput::MAX_DURATION_SECONDS],
            'description' => ['nullable', 'string'],
            'billable' => ['nullable', 'boolean'],
            // Shape and size are decided by TimeEntryInput::external() on the raw body (D-15).
            'external' => ['nullable'],
        ];
    }

    /**
     * The data of `CreateTimeEntry::handle()` for a validated request.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forCreate(array $validated, ReferenceResolver $refs, ?stdClass $raw = null): array
    {
        $start = TimeEntryInput::instant($validated['started_at'] ?? null, 'started_at');
        $data = [
            'client_id' => $validated['client_id'] ?? null,
            'project_id' => $refs->writeProject($validated['project_id'] ?? null),
            'task_id' => $refs->writeTask($validated['task_id'] ?? null),
            'description' => $validated['description'] ?? null,
            'started_at' => $start,
            'ended_at' => self::end($validated, $start),
        ];

        if (isset($validated['billable'])) {
            $data['billable'] = filter_var($validated['billable'], FILTER_VALIDATE_BOOLEAN);
        }

        return [...$data, ...self::external($validated, $raw)];
    }

    /**
     * The data of `CreateTimeEntry::handle()` for one item of a batch (D-14).
     *
     * The item is validated here with the rules of a single create, so a bad item raises its own
     * `ValidationException` keyed by the item's field names and never fails the batch. An item must be a JSON
     * object: `$rawItem` is the same item decoded as objects (`rawBody()`), which tells `{}` from `[]` and
     * is also where `external` is taken from, exactly as for a single create.
     *
     * @param  mixed  $item  the item as Laravel decoded it (an array for an object or a list, a scalar otherwise)
     * @param  mixed  $rawItem  the item decoded as objects
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forBatchItem(mixed $item, mixed $rawItem, ReferenceResolver $refs): array
    {
        if (! is_array($item) || ! $rawItem instanceof stdClass) {
            throw ValidationException::withMessages(['entry' => __('api.errors.batch_item_not_object')]);
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($item, self::rules(true))->validate();

        return self::forCreate($validated, $refs, $rawItem);
    }

    /**
     * The data of `UpdateTimeEntry::handle()` for a validated request: only the keys the request names,
     * because an absent key keeps the stored value and a null clears the project or the task.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forUpdate(array $validated, TimeEntry $entry, ReferenceResolver $refs, ?stdClass $raw = null): array
    {
        $data = [];

        foreach (['client_id', 'description'] as $key) {
            if (array_key_exists($key, $validated)) {
                $data[$key] = $validated[$key];
            }
        }

        if (array_key_exists('project_id', $validated)) {
            $data['project_id'] = $refs->writeProject($validated['project_id']);
        }

        if (array_key_exists('task_id', $validated)) {
            $data['task_id'] = $refs->writeTask($validated['task_id']);
        }

        if (isset($validated['billable'])) {
            $data['billable'] = filter_var($validated['billable'], FILTER_VALIDATE_BOOLEAN);
        }

        $start = $entry->started_at;

        if (array_key_exists('started_at', $validated)) {
            $start = TimeEntryInput::instant($validated['started_at'], 'started_at');
            $data['started_at'] = $start;
        }

        if (isset($validated['duration_seconds']) || array_key_exists('ended_at', $validated)) {
            $data['ended_at'] = self::end($validated, $start);
        }

        return [...$data, ...self::external($validated, $raw)];
    }

    /**
     * The `external` key of the Action data: present exactly when the request names it. The value is the
     * raw object (or null, or whatever else was sent, for the Action to refuse). Without a JSON object
     * body, a form post for example, a named `external` is an array, which the Action refuses too.
     *
     * @param  array<string, mixed>  $validated
     * @return array{external?: mixed}
     */
    private static function external(array $validated, ?stdClass $raw): array
    {
        if ($raw !== null) {
            return property_exists($raw, 'external') ? ['external' => $raw->external] : [];
        }

        return array_key_exists('external', $validated) ? ['external' => $validated['external']] : [];
    }

    /**
     * The end of the entry: the named `ended_at`, or the start plus `duration_seconds`. Both forms pass
     * `TimeEntryInput::instant()` (UTC years 1 to 9999, whole second), so an end the database cannot store
     * is refused under `ended_at` with `invalid_time` here, before the Action runs. An interval beyond the
     * column limit is refused next, under `ended_at`, by the domain rule `TimeEntryInput::assertDurationFits()`.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private static function end(array $validated, ?CarbonImmutable $start): ?CarbonImmutable
    {
        $duration = $validated['duration_seconds'] ?? null;

        $end = $duration !== null
            ? TimeEntryInput::instant($start?->addSeconds((int) $duration), 'ended_at')
            : TimeEntryInput::instant($validated['ended_at'] ?? null, 'ended_at');

        if ($start !== null && $end !== null) {
            TimeEntryInput::assertDurationFits($start, $end, 'ended_at');
        }

        return $end;
    }

    /**
     * A rule that accepts an instant with an offset and refuses the rest with the message of the panel.
     */
    private static function instant(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match(self::INSTANT, $value) !== 1) {
                $fail(__('kokpit.time.errors.invalid_time'));
            }
        };
    }

    /**
     * A rule that accepts a uuid and refuses the rest with the given message of the panel.
     */
    private static function uuid(string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (! is_string($value) || ! Str::isUuid($value)) {
                $fail(__('kokpit.time.errors.'.$message));
            }
        };
    }

    /**
     * A rule that accepts a uuid or a human key (a project key or a task reference) and refuses a name or
     * any other string with the given message of the panel (D-13).
     */
    private static function reference(string $pattern, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($pattern, $message): void {
            if (! is_string($value) || (! Str::isUuid($value) && preg_match($pattern, $value) !== 1)) {
                $fail(__('kokpit.time.errors.'.$message));
            }
        };
    }
}

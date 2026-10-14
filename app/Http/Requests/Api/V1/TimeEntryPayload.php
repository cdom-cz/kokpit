<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The write payload of a time entry: the shape rules and the mapping to the data of the domain Actions.
 *
 * The rules check shape only (types, uuid or key syntax, an ISO 8601 instant with an offset, exactly one
 * of `ended_at` and `duration_seconds`, the integer bound). Every business rule, the context agreement,
 * the end after the start and the billed lock, stays in the Actions, so the API refuses what the panel
 * refuses with the same field keys and messages. The mapping builds only the allow-listed Action keys:
 * a smuggled `user_id`, `billing_state`, `billed_at` or `id` never leaves this class.
 *
 * `duration_seconds` is an alternative to `ended_at` that exists only here: it becomes
 * `ended_at = started_at + duration` before the Action, which takes `ended_at` only.
 */
final class TimeEntryPayload
{
    /**
     * An instant with an offset or `Z`. The year 0000 does not exist in PostgreSQL, so it is refused here
     * instead of crashing the insert.
     */
    public const string INSTANT = '/^(?!0000)\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * The largest interval the generated `duration_seconds` column (a PostgreSQL integer) can hold. It is
     * the column limit, not a product cap: the API refuses nothing the panel accepts except what would
     * crash the database.
     */
    public const int MAX_DURATION_SECONDS = 2147483647;

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
            'duration_seconds' => ['integer', 'min:1', 'max:'.self::MAX_DURATION_SECONDS],
            'description' => ['nullable', 'string'],
            'billable' => ['nullable', 'boolean'],
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
    public static function forCreate(array $validated, ReferenceResolver $refs): array
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

        return $data;
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
    public static function forUpdate(array $validated, TimeEntry $entry, ReferenceResolver $refs): array
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

        return $data;
    }

    /**
     * The end of the entry: the named `ended_at`, or the start plus `duration_seconds`. An interval beyond
     * the column limit is refused under `ended_at` before the database sees it.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private static function end(array $validated, ?CarbonImmutable $start): ?CarbonImmutable
    {
        $duration = $validated['duration_seconds'] ?? null;

        $end = $duration !== null
            ? $start?->addSeconds((int) $duration)
            : TimeEntryInput::instant($validated['ended_at'] ?? null, 'ended_at');

        if ($start !== null && $end !== null && $end->getTimestamp() - $start->getTimestamp() > self::MAX_DURATION_SECONDS) {
            throw ValidationException::withMessages(['ended_at' => __('api.errors.duration_too_long')]);
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

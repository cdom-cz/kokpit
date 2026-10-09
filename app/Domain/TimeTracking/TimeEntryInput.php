<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The input rules every time entry writer shares: StartTimer now, CreateTimeEntry
 * and UpdateTimeEntry later. One place answers the same field error for the same
 * bad value.
 */
final class TimeEntryInput
{
    private const int DESCRIPTION_MAX_LENGTH = 1000;

    /**
     * The client, project and task of an entry, each read again under a share
     * lock so an archive in flight cannot slip past the check.
     *
     * MUST run inside the caller's transaction, otherwise the share locks end
     * with the statement. Lock order: task, project, client; the same order in
     * every writer. A task fixes the project and the client: a given project or
     * client is only compared with the derived one, so a forged combination can
     * never be stored. Only a selectable project counts (live, live client).
     *
     * Field errors: `task_id` for a task that is malformed, unknown, archived or
     * whose project is not selectable; `project_id` for a project that is not
     * selectable; `client_id` for a missing or archived client;
     * `inconsistent_context` on `task_id` when the task and the project differ
     * and on `project_id` when the project and the client differ.
     *
     * @param  array{client_id?: mixed, project_id?: mixed, task_id?: mixed}  $data
     * @return array{client: Client, project: Project|null, task: Task|null}
     *
     * @throws ValidationException
     */
    public static function context(array $data): array
    {
        $taskId = self::id($data['task_id'] ?? null, 'task_id', 'task_unavailable');
        $projectId = self::id($data['project_id'] ?? null, 'project_id', 'project_unavailable');
        $clientId = self::id($data['client_id'] ?? null, 'client_id', 'client_required');

        $task = null;
        $project = null;

        if ($taskId !== null) {
            // The soft-delete scope hides an archived task.
            $task = Task::query()->whereKey($taskId)->sharedLock()->first()
                ?? throw self::error('task_id', 'task_unavailable');

            $project = Project::query()->selectable()->whereKey($task->project_id)->sharedLock()->first()
                ?? throw self::error('task_id', 'task_unavailable');

            if ($projectId !== null && $projectId !== $project->getKey()) {
                throw self::error('task_id', 'inconsistent_context');
            }
        } elseif ($projectId !== null) {
            $project = Project::query()->selectable()->whereKey($projectId)->sharedLock()->first()
                ?? throw self::error('project_id', 'project_unavailable');
        }

        if ($project !== null) {
            if ($clientId !== null && $clientId !== $project->client_id) {
                throw self::error('project_id', 'inconsistent_context');
            }

            $clientId = $project->client_id;
        }

        $client = $clientId === null ? null : Client::query()->whereKey($clientId)->sharedLock()->first();

        if ($client === null) {
            throw self::error('client_id', 'client_required');
        }

        return ['client' => $client, 'project' => $project, 'task' => $task];
    }

    /**
     * The description as stored: trimmed, null when empty.
     *
     * @throws ValidationException a field error on `description` for more than 1000 characters
     */
    public static function description(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $description = trim($value);

        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw self::error('description', 'description_too_long');
        }

        return $description === '' ? null : $description;
    }

    /**
     * An instant of a manual entry, truncated to the whole second (never rounded).
     *
     * Accepted: a CarbonInterface, an ISO 8601 string with an offset or `Z`
     * (`2026-10-12T10:00:00+02:00`, optional fraction), or `Y-m-d H:i:s`
     * (optional fraction) read in the application timezone, which is how the
     * Filament pickers dehydrate a Prague wall-clock value to UTC. Null and an
     * empty string are absent. A calendar day that does not exist is rejected,
     * not rolled over.
     *
     * @param  string  $field  the data key the field error is keyed by (`started_at` or `ended_at`)
     * @return CarbonImmutable|null null for an absent value
     *
     * @throws ValidationException the field error `invalid_time` on `$field` for anything unparsable
     */
    public static function instant(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return self::normalised($value);
        }

        if (! is_string($value)) {
            throw self::error($field, 'invalid_time');
        }

        $text = trim($value);

        $parsed = null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/', $text) === 1) {
            $parsed = self::parse($text, str_contains($text, '.') ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP', null);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,9})?$/', $text) === 1) {
            $parsed = self::parse($text, str_contains($text, '.') ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s', new DateTimeZone((string) config('app.timezone')));
        }

        return $parsed === null
            ? throw self::error($field, 'invalid_time')
            : self::normalised($parsed);
    }

    /**
     * The end of a finished entry must lie strictly after its start.
     *
     * @throws ValidationException the field error `end_before_start` on `ended_at`
     */
    public static function assertEndAfterStart(CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw self::error('ended_at', 'end_before_start');
        }
    }

    /**
     * The refusal of a write to a billed entry (D-06): it is unlocked only
     * through the cancel action of the billing.
     */
    public static function locked(): DomainException
    {
        return new DomainException(__('kokpit.time.errors.locked'));
    }

    /**
     * Whether the database refused the statement through the frozen-row guard
     * (SQLSTATE KP001), which is how a billed entry answers a write that got
     * past the application check.
     */
    public static function isFrozenRowRefusal(QueryException $e): bool
    {
        $previous = $e->getPrevious();
        $state = $previous instanceof \PDOException && is_array($previous->errorInfo) && isset($previous->errorInfo[0])
            ? (string) $previous->errorInfo[0]
            : (string) $e->getCode();

        return $state === 'KP001';
    }

    /**
     * The instant in the application timezone, truncated to the whole second.
     *
     * The model casts write the wall-clock value of the instant's own zone, so
     * an instant carrying a +02:00 offset must be moved to UTC before it is
     * stored, or it would be stored two hours late.
     */
    private static function normalised(CarbonInterface $value): CarbonImmutable
    {
        return TimerClock::truncate($value)->setTimezone((string) config('app.timezone'));
    }

    /**
     * Strict parse of one format: null for a mismatch or a day that PHP would roll over.
     */
    private static function parse(string $text, string $format, ?DateTimeZone $timezone): ?CarbonImmutable
    {
        // Fractions beyond microseconds carry nothing a whole second needs.
        $text = (string) preg_replace('/(\.\d{6})\d+/', '$1', $text);
        $date = DateTimeImmutable::createFromFormat($format, $text, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return CarbonImmutable::instance($date);
    }

    /**
     * A uuid string, null for an absent value (null or an empty string).
     *
     * @throws ValidationException the field error of the key for anything else
     */
    private static function id(mixed $value, string $key, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw self::error($key, $message);
        }

        return $value;
    }

    private static function error(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => __('kokpit.time.errors.'.$message)]);
    }
}

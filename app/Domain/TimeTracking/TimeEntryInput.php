<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
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

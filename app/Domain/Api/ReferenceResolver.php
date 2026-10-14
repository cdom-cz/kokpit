<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Str;

/**
 * Turns the references an API client may send into identifiers (D-12, D-13).
 *
 * A client, project or task is named by its UUID, and a project or task also by its human key
 * (`DEMO`, `DEMO-1`); a name is never accepted. The result is the UUID or null when nothing matches,
 * and a string that is neither a UUID nor a well formed key never reaches a query (a malformed value
 * on a uuid column would fail the whole query). The key patterns match the whole string, a trailing
 * line feed included (the `D` modifier). Archived projects and tasks resolve too, because
 * the time entries of archived work stay readable.
 */
final class ReferenceResolver
{
    public const string PROJECT_KEY = '/^[A-Z]{2,6}$/D';

    public const string TASK_REFERENCE = '/^[A-Z]{2,6}-[1-9][0-9]*$/D';

    /**
     * A client is referenced by UUID only; there is no key.
     */
    public function clientId(string $ref): ?string
    {
        return Str::isUuid($ref) ? $ref : null;
    }

    public function projectId(string $ref): ?string
    {
        if (Str::isUuid($ref)) {
            return $ref;
        }

        if (preg_match(self::PROJECT_KEY, $ref) !== 1) {
            return null;
        }

        $id = Project::withTrashed()->where('key', $ref)->value('id');

        return is_string($id) ? $id : null;
    }

    public function taskId(string $ref): ?string
    {
        if (Str::isUuid($ref)) {
            return $ref;
        }

        if (preg_match(self::TASK_REFERENCE, $ref) !== 1) {
            return null;
        }

        $id = Task::withTrashed()->where('reference', $ref)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * The project reference of a write as the Action expects it: a uuid or the id of the project the key
     * names. Anything else (null, a non-string, a string that is no key or names no project) passes through
     * unchanged, so the Action refuses it with its own field error and the API carries no copy of that rule.
     * An archived project resolves too; the Action then refuses it as the panel does.
     */
    public function writeProject(mixed $value): mixed
    {
        return is_string($value) ? ($this->projectId($value) ?? $value) : $value;
    }

    /**
     * The task counterpart of {@see writeProject()}: a uuid or the id of the task a `KEY-N` reference names.
     */
    public function writeTask(mixed $value): mixed
    {
        return is_string($value) ? ($this->taskId($value) ?? $value) : $value;
    }

    /**
     * The client of the uuid when it is selectable (not archived), else null. A lookup offers what the pickers
     * offer; archived work stays reachable only through the ids old time entries carry (research A13).
     */
    public function selectableClient(string $ref): ?Client
    {
        return Str::isUuid($ref) ? Client::query()->whereKey($ref)->first() : null;
    }

    /**
     * The selectable project (not archived, client not archived) of a uuid or a project key, else null.
     */
    public function selectableProject(string $ref): ?Project
    {
        if (Str::isUuid($ref)) {
            return Project::query()->selectable()->whereKey($ref)->first();
        }

        if (preg_match(self::PROJECT_KEY, $ref) !== 1) {
            return null;
        }

        return Project::query()->selectable()->where('projects.key', $ref)->first();
    }

    /**
     * The selectable task (not archived, of a selectable project) of a uuid or a `KEY-N` reference, else null.
     */
    public function selectableTask(string $ref): ?Task
    {
        $query = Task::query()->whereIn('tasks.project_id', Project::query()->selectable()->select('projects.id'));

        if (Str::isUuid($ref)) {
            return $query->whereKey($ref)->first();
        }

        if (preg_match(self::TASK_REFERENCE, $ref) !== 1) {
            return null;
        }

        return $query->where('tasks.reference', $ref)->first();
    }
}

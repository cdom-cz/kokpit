<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Str;

/**
 * Turns the references an API client may send into identifiers (D-12, D-13).
 *
 * A client, project or task is named by its UUID, and a project or task also by its human key
 * (`DEMO`, `DEMO-1`); a name is never accepted. The result is the UUID or null when nothing matches,
 * and a string that is neither a UUID nor a well formed key never reaches a query (a malformed value
 * on a uuid column would fail the whole query). Archived projects and tasks resolve too, because
 * the time entries of archived work stay readable.
 */
final class ReferenceResolver
{
    public const string PROJECT_KEY = '/^[A-Z]{2,6}$/';

    public const string TASK_REFERENCE = '/^[A-Z]{2,6}-[1-9][0-9]*$/';

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
}

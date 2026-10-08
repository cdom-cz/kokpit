<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\KokpitPolicy;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Database\Eloquent\Model;

/**
 * A Partner may list comments, read a non-internal comment of a task they can
 * view, and create one. Nobody edits or deletes a comment (append-only,
 * research A6): `update` and `delete` keep the base denial, and the Admin is
 * admitted by KokpitPolicy::before() but no action offers it.
 *
 * The scoped query already hides internal comments and other clients' tasks;
 * `view` is the defence in depth for a record that reached the policy some
 * other way (loaded in a system run). `create` is true because the comment
 * ability on the task, checked by AddTaskComment, is the guard.
 */
final class TaskCommentPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof TaskComment || $record->is_internal || $user->client_id === null) {
            return false;
        }

        // The relation goes through the Partner-scoped Task query: a task that is
        // not visible to the Partner is null here.
        $task = $record->task;

        return $task instanceof Task && $user->can('view', $task);
    }

    public function create(User $user): bool
    {
        return true;
    }
}

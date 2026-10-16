<?php

declare(strict_types=1);

namespace App\Domain\Documents\Policies;

use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Auth\KokpitPolicy;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Database\Eloquent\Model;

/**
 * Files are managed by the Admin, who passes through KokpitPolicy::before(). A Partner may list documents
 * (the scoped query decides which rows exist for them), read a shared file (D-03, D-04), attach files to an
 * own open task or an own non-internal comment and delete an own upload while the task is open (D-05); every
 * other ability, renaming and the visibility switch included, is denied for a Partner.
 *
 * `attach` is declared on purpose: the Gate skips `before()` for an ability the policy has no method for,
 * so without it the Admin would be refused too. Its second argument is the owner the file would hang on
 * (null for an unattached file).
 *
 * "Open" means the task is not archived and its status is not `done` (D-19). Every Partner read goes through
 * the scoped `Task` and `TaskComment` queries, so a task of another client, of a project that is not
 * client-visible or of an archived project never counts as readable.
 */
final class DocumentPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * D-03 and D-04: the Document scope (`constrainForPartner`) is the rule, this method repeats it for a record
     * that reached the policy some other way. A Partner reads a file that is flagged visible, hangs on a project,
     * task or non-internal comment, and that the Partner-scoped query still returns: that last read is what
     * drops another client's file, an internal-comment file and a file of an archived owner (D-21).
     */
    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof Document || ! $record->visible_to_client || $user->client_id === null) {
            return false;
        }

        $owner = $record->owner_type === null ? null : DocumentOwnerType::tryFrom($record->owner_type);

        if ($owner === null || ! $owner->isShareable()) {
            return false;
        }

        return Document::query()->whereKey($record->getKey())->exists();
    }

    /**
     * D-05: a Partner attaches files to a task they requested or to a non-internal comment they wrote, both
     * readable through the scoped query and on an open task. A project, a client, no owner, someone else's
     * task or comment, an internal comment and a closed or archived task are all refused.
     */
    public function attach(User $user, ?Model $owner = null): bool
    {
        if ($owner instanceof Task) {
            return $owner->requester_id === $user->getKey() && $this->openTask($owner->getKey()) !== null;
        }

        if ($owner instanceof TaskComment) {
            if ($owner->author_id !== $user->getKey() || $owner->is_internal) {
                return false;
            }

            $comment = TaskComment::query()->whereKey($owner->getKey())->first();

            return $comment !== null
                && ! $comment->is_internal
                && $comment->author_id === $user->getKey()
                && $this->openTask($comment->task_id) !== null;
        }

        return false;
    }

    /**
     * Rename and the visibility switch are Admin-only (D-05): the Partner's upload stays visible and keeps its name.
     */
    public function update(User $user, Model $record): bool
    {
        return false;
    }

    /**
     * D-05: a Partner deletes a file they uploaded themselves, that they can still read, while the task it
     * hangs on (or the task of its comment) is open. Everything else, the Admin's files and a closed or
     * archived task included, is refused; the Admin passes through `before()`.
     */
    public function delete(User $user, Model $record): bool
    {
        if (! $record instanceof Document || $record->uploaded_by_id !== $user->getKey() || ! $this->view($user, $record)) {
            return false;
        }

        $taskId = match ($record->owner_type) {
            DocumentOwnerType::Task->value => $record->owner_id,
            DocumentOwnerType::TaskComment->value => TaskComment::query()->whereKey($record->owner_id)->value('task_id'),
            default => null,
        };

        return is_string($taskId) && $this->openTask($taskId) !== null;
    }

    /**
     * The task as the Partner-scoped query returns it (not archived, own client, client-visible project) when
     * it is not done; otherwise null.
     */
    private function openTask(mixed $id): ?Task
    {
        $task = Task::query()->whereKey($id)->first();

        return $task !== null && ! $task->trashed() && $task->status !== ProjectStatus::Done ? $task : null;
    }
}

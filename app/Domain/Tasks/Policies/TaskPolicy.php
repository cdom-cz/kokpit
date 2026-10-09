<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Auth\KokpitPolicy;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Model;

/**
 * A Partner may list tasks, view a task of a visible project of the own
 * client, create a task, comment on a task they can view and escalate it. The
 * Admin is admitted by KokpitPolicy::before().
 *
 * Editing, deleting and restoring stay denied for every Partner. The one Partner
 * write of an existing task's content is `editDescription` (gap G-05-5, D-16, the
 * owner's decision): the description of a task of an own client-visible project,
 * only while the task is not archived and its status is one of
 * DESCRIPTION_EDITABLE_STATUSES ("Plánovaný" or "K upřesnění"), whatever its
 * requester or assignee. Status, priority, people, dates and tags stay with the
 * Admin, who passes through before(). Clearing an
 * escalation is granted to a Partner only on a task of the own client whose
 * assignee is that Partner (D-06: the assignee resolves the flag); the Admin
 * may always clear it. Being the assignee gives no other right: a Partner
 * assignee still cannot change priority or status. The
 * scoped query already hides every task of another client, so `view` is the
 * defence in depth for a record that reached the policy some other way.
 * `create` is true because the scoped project lookup inside CreateTask is the
 * project guard.
 */
final class TaskPolicy extends KokpitPolicy
{
    /**
     * The statuses in which a Partner may edit the description of an own task
     * (D-16, owner decision from UAT G-05-5). Every other status is read-only for
     * the Partner. The one definition: the action visibility, the pre-check and
     * the re-check on the locked row all go through `editDescription`.
     */
    public const array DESCRIPTION_EDITABLE_STATUSES = [ProjectStatus::Planned, ProjectStatus::ToClarify];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return $this->ownsProjectOf($user, $record);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function comment(User $user, Model $record): bool
    {
        return $this->ownsProjectOf($user, $record);
    }

    public function escalate(User $user, Model $record): bool
    {
        return $this->ownsProjectOf($user, $record);
    }

    public function clearEscalation(User $user, Model $record): bool
    {
        return $this->ownsProjectOf($user, $record)
            && $record instanceof Task
            && $record->assignee_id === $user->id;
    }

    public function editDescription(User $user, Model $record): bool
    {
        return $this->ownsProjectOf($user, $record)
            && $record instanceof Task
            && ! $record->trashed()
            && in_array($record->status, self::DESCRIPTION_EDITABLE_STATUSES, true);
    }

    private function ownsProjectOf(User $user, Model $record): bool
    {
        if (! $record instanceof Task || $user->client_id === null) {
            return false;
        }

        // The relation goes through the Partner-scoped Project query: a project
        // that is not visible to the Partner is null here.
        $project = $record->project;

        return $project !== null && $project->client_id === $user->client_id;
    }
}

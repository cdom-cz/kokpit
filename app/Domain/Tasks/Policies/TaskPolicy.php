<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\KokpitPolicy;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Model;

/**
 * A Partner may list tasks, view a task of a visible project of the own
 * client, create a task, comment on a task they can view and escalate it. The
 * Admin is admitted by KokpitPolicy::before().
 *
 * Editing, deleting and restoring stay denied, and so does clearing an
 * escalation: a later plan grants that to the Partner who is the assignee. The
 * scoped query already hides every task of another client, so `view` is the
 * defence in depth for a record that reached the policy some other way.
 * `create` is true because the scoped project lookup inside CreateTask is the
 * project guard.
 */
final class TaskPolicy extends KokpitPolicy
{
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
        return false;
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

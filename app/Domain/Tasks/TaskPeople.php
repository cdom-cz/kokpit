<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Projects\Models\Project;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Who a task may be assigned to and requested by, and the defaults (D-04, D-05).
 *
 * The allowed people of a task are the active Admin and the active Partner
 * accounts whose client owns the project. An account of another client, a
 * deactivated account, a user without a role and an unknown id are all outside
 * the set. The set is recomputed here on every write, never taken from a form,
 * and never checked with an `exists:` validation rule, which would tell a caller
 * which ids exist.
 *
 * Users are not Partner scoped, so the queries work inside a Partner request.
 */
final class TaskPeople
{
    /**
     * The Admin who receives the tasks of Partners: the oldest active account
     * with the Admin role (there is one in practice), by creation time, then id.
     *
     * @throws DomainException when no active Admin exists
     */
    public function admin(): User
    {
        $admin = $this->activeWithRole(RoleName::Admin)->orderBy('created_at')->orderBy('id')->first();

        return $admin ?? throw new DomainException(__('kokpit.tasks.errors.no_admin'));
    }

    /**
     * @return list<string>
     */
    public function allowedIds(Project $project): array
    {
        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $this->allowedQuery($project)->pluck('id')->all(),
        ));
    }

    /**
     * The people a picker may offer for the project, id => name.
     *
     * @return array<string, string>
     */
    public function options(Project $project): array
    {
        /** @var array<string, string> $options */
        $options = $this->allowedQuery($project)->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();

        return $options;
    }

    /**
     * @throws ValidationException a field error on `$field` when the account is not allowed
     */
    public function assertAllowed(Project $project, string $userId, string $field): void
    {
        if (! in_array($userId, $this->allowedIds($project), true)) {
            throw ValidationException::withMessages([$field => __('kokpit.tasks.errors.person_not_allowed')]);
        }
    }

    /**
     * The requester and assignee of a new task when nobody was chosen (D-04).
     * The Admin is both. A Partner is the requester and the Admin the assignee.
     *
     * @return array{requester_id: string, assignee_id: string}
     *
     * @throws DomainException for a Partner when no active Admin exists
     */
    public function defaultsFor(User $actor): array
    {
        if ($this->isPartner($actor)) {
            return ['requester_id' => $actor->id, 'assignee_id' => $this->admin()->id];
        }

        return ['requester_id' => $actor->id, 'assignee_id' => $actor->id];
    }

    public function isPartner(User $actor): bool
    {
        return $actor->hasRole(RoleName::Partner->value);
    }

    /**
     * @return Builder<User>
     */
    private function allowedQuery(Project $project): Builder
    {
        return User::query()
            ->whereNull('deactivated_at')
            ->where(static function (Builder $query) use ($project): void {
                $query
                    ->whereHas('roles', static fn (Builder $roles) => $roles->where('name', RoleName::Admin->value))
                    ->orWhere(static function (Builder $partners) use ($project): void {
                        $partners
                            ->where('client_id', $project->client_id)
                            ->whereHas('roles', static fn (Builder $roles) => $roles->where('name', RoleName::Partner->value));
                    });
            });
    }

    /**
     * @return Builder<User>
     */
    private function activeWithRole(RoleName $role): Builder
    {
        return User::query()
            ->whereNull('deactivated_at')
            ->whereHas('roles', static fn (Builder $roles) => $roles->where('name', $role->value));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Projects\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\KokpitPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * A Partner may list projects and view a client-visible project of the own
 * client; every other ability keeps the base denial. The Admin is admitted by
 * KokpitPolicy::before(). The query scope already hides everything else, so
 * `view` is the defence in depth for a single record that reached the policy
 * some other way.
 */
final class ProjectPolicy extends KokpitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof Project
            && $user->client_id !== null
            && $record->client_id === $user->client_id
            && $record->client_visible === true;
    }
}

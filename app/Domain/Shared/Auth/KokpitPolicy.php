<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Illuminate\Database\Eloquent\Model;

/**
 * Default-deny base of every Kokpit policy (D-02).
 *
 * `before()` holds the one explicit Admin rule and turns away everybody who is
 * not a Partner with a client. For a valid Partner it returns null, so the
 * ability methods decide, and every one of them denies until a subclass
 * overrides it with an explicit grant. There is deliberately no global gate
 * callback: the Admin rule lives here and nowhere else.
 *
 * Ability methods take `Model $record`, not a concrete model, so a subclass can
 * override them without narrowing the signature; it checks the type itself.
 */
abstract class KokpitPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasRole(RoleName::Admin->value)) {
            return true;
        }

        $clientId = $user->client_id;

        if ($user->hasRole(RoleName::Partner->value) && is_string($clientId) && $clientId !== '') {
            return null;
        }

        return false;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Model $record): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return false;
    }
}

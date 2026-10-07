<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Illuminate\Support\Facades\Auth;

/**
 * Who is asking, as far as data access is concerned (D-02).
 *
 * Bound with `scoped()` by AccessServiceProvider, so the system flag lives for
 * one request, console run or queue job and is reset between them. A plain
 * binding would hand every resolution a fresh instance, and the flag set by
 * `runAsSystem()` would be invisible to the global scope.
 *
 * Nothing here trusts the request: the user comes from the default guard and
 * the role from the permission package. Anything that is not clearly the Admin,
 * a Partner with a client or an explicit system run is treated as nobody.
 */
final class PartnerContext
{
    private bool $system = false;

    public function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function isAdmin(): bool
    {
        return $this->user()?->hasRole(RoleName::Admin->value) === true;
    }

    /**
     * The client of the signed-in Partner, or null for everybody else: the
     * Admin, a guest, a user without a role, an unknown role and a Partner
     * whose `client_id` is empty.
     */
    public function partnerClientId(): ?string
    {
        $user = $this->user();

        if ($user === null || ! $user->hasRole(RoleName::Partner->value)) {
            return null;
        }

        $clientId = $user->client_id;

        return is_string($clientId) && $clientId !== '' ? $clientId : null;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * Runs work that has no signed-in user (console commands, seeders, jobs)
     * without the fail-closed scopes. The previous flag is restored even when
     * the callback throws, so the escape can neither be lost nor leak out.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAsSystem(callable $callback): mixed
    {
        $previous = $this->system;
        $this->system = true;

        try {
            return $callback();
        } finally {
            $this->system = $previous;
        }
    }
}

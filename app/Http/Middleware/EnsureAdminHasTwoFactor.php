<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Forces the Admin, and only the Admin, to have two-factor authentication (D-06).
 *
 * Filament decides once, while the routes are built, whether multi-factor
 * authentication is required, so it cannot depend on who is signed in. This
 * middleware is attached to every panel route instead and decides per request:
 * it passes through unless enforcement is on and the user is an Admin, and then
 * defers to Filament, which redirects to the set-up page while no provider is
 * enabled. Partners are never forced.
 */
class EnsureAdminHasTwoFactor extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (config('kokpit.require_admin_two_factor') !== true) {
            return $next($request);
        }

        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->hasRole(RoleName::Admin->value)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}

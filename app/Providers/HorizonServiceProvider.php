<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Access rule of the Horizon dashboard (FND-06).
 *
 * The dashboard lists job payloads, so only the Admin may open it. The Horizon routes sit outside
 * the Filament panel, so the panel's per-request Admin two-factor check (D-06) does not cover
 * them; the gate repeats it. The package default also lets everyone in when APP_ENV is local;
 * authorization() is overridden to remove that shortcut, because a Partner must never reach the
 * dashboard in any environment.
 *
 * No Horizon notification route is set: a failed job already alerts the Admin (ReportFailedJob)
 * and a slow queue shows on the System page.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(static fn (Request $request): bool => Gate::forUser($request->user())->allows('viewHorizon'));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (User $user): bool {
            if (! $user->hasRole(RoleName::Admin->value)) {
                return false;
            }

            if (config('kokpit.require_admin_two_factor') !== true) {
                return true;
            }

            $secret = $user->getAppAuthenticationSecret();

            return is_string($secret) && $secret !== '';
        });
    }
}

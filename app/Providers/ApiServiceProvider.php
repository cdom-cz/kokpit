<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Wires the REST API: who may hold a working token.
 *
 * Sanctum already refuses an unknown, revoked or expired token. The callback
 * below is the single choke point that also refuses a token whose owner is not
 * an active Admin, so a Partner, a user without a role or a deactivated Admin
 * gets 401 even when a token row exists for them (a token created in tinker, or
 * kept from before a deactivation).
 */
final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::authenticateAccessTokensUsing(static function (PersonalAccessToken $token, bool $isValid): bool {
            $user = $token->tokenable;

            return $isValid
                && $user instanceof User
                && $user->deactivated_at === null
                && $user->hasRole(RoleName::Admin->value);
        });
    }
}

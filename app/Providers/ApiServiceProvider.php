<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Wires the REST API: who may hold a working token, and how fast it may be used.
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

        // Reads and writes are separate buckets (D-16), and each token has its own. The limit is read
        // when the request arrives, so a changed config value applies at once. ThrottleRequests runs
        // after authentication, so the token is known; the IP address is only the fallback.
        RateLimiter::for('api-read', static fn (Request $request): Limit => Limit::perMinute((int) config('kokpit.api.rate_limit.read'))
            ->by(self::bucketKey($request)));
        RateLimiter::for('api-write', static fn (Request $request): Limit => Limit::perMinute((int) config('kokpit.api.rate_limit.write'))
            ->by(self::bucketKey($request)));

        // The public documentation has no token, so its bucket is the client address (behind the balancer
        // the trusted-proxy configuration makes it the real one).
        RateLimiter::for('api-docs', static fn (Request $request): Limit => Limit::perMinute((int) config('kokpit.api.docs_rate_limit'))
            ->by((string) $request->ip()));
    }

    /**
     * The id of the token the request authenticated with, or the client address without one.
     */
    private static function bucketKey(Request $request): string
    {
        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        return is_scalar($tokenId) ? (string) $tokenId : (string) $request->ip();
    }
}

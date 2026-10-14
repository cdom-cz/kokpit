<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\ApiTokenExpiry;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues an API token to the Admin who asks for it (AP-01, D-01, D-02).
 *
 * The abilities come from the ApiAbility enum only, so a forged value such as `*` can
 * never reach a token. The plain text of the returned token exists only in the
 * return value; the database holds its hash.
 */
final class IssueApiToken
{
    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function handle(User $actor, string $name, array $abilities, ApiTokenExpiry $expiry): NewAccessToken
    {
        return $actor->createToken(
            $name,
            array_map(static fn (ApiAbility $ability): string => $ability->value, $abilities),
            $expiry->expiresAt(now()),
        );
    }
}

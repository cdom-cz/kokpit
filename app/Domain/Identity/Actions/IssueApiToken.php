<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\ApiTokenExpiry;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues an API token to the Admin who asks for it (AP-01, D-01, D-02).
 *
 * Only an active Admin may issue one. The abilities come from the ApiAbility enum only,
 * so a forged value such as `*` can never reach a token. The plain text of the returned
 * token exists only in the return value; the database holds its hash, and the activity
 * row (U-12) holds the name and the abilities and nothing else.
 */
final class IssueApiToken
{
    /** The longest token name, in characters. */
    public const int NAME_MAX = 80;

    /**
     * @param  list<ApiAbility>  $abilities
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $name, array $abilities, ApiTokenExpiry $expiry): NewAccessToken
    {
        if ($actor->deactivated_at !== null || ! $actor->hasRole(RoleName::Admin->value)) {
            throw new AuthorizationException;
        }

        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('kokpit.api_tokens.errors.name_required')]);
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw ValidationException::withMessages(['name' => __('kokpit.api_tokens.errors.name_too_long')]);
        }

        $values = array_values(array_unique(array_map(static fn (ApiAbility $ability): string => $ability->value, $abilities)));

        if ($values === []) {
            throw ValidationException::withMessages(['abilities' => __('kokpit.api_tokens.errors.abilities_required')]);
        }

        return DB::transaction(static function () use ($actor, $name, $values, $expiry): NewAccessToken {
            $issued = $actor->createToken($name, $values, $expiry->expiresAt(now()));

            activity()
                ->useLog('api_token')
                ->performedOn($issued->accessToken)
                ->causedBy($actor)
                ->event('api_token.created')
                ->withProperties(['name' => $name, 'abilities' => $values])
                ->log('api_token.created');

            return $issued;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revokes one of the Admin's own API tokens (AP-05, D-02, D-03).
 *
 * The row is deleted only where it belongs to the actor: the owner check happens here, on
 * the server, with the key the caller names, so a forged record key can never reach another
 * user's token. Returns false when nothing matched (already revoked, not the actor's, not
 * even a uuid), and one activity row with the name and abilities only when a token was deleted.
 */
final class RevokeApiToken
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, string $tokenId): bool
    {
        if ($actor->deactivated_at !== null || ! $actor->hasRole(RoleName::Admin->value)) {
            throw new AuthorizationException;
        }

        // A key that is no uuid would reach the uuid column and raise a database error.
        if (! Str::isUuid($tokenId)) {
            return false;
        }

        return DB::transaction(static function () use ($actor, $tokenId): bool {
            $token = PersonalAccessToken::query()
                ->whereKey($tokenId)
                ->where('tokenable_type', $actor->getMorphClass())
                ->where('tokenable_id', $actor->getKey())
                ->lockForUpdate()
                ->first();

            if (! $token instanceof PersonalAccessToken) {
                return false;
            }

            activity()
                ->useLog('api_token')
                ->performedOn($token)
                ->causedBy($actor)
                ->event('api_token.revoked')
                ->withProperties(['name' => $token->name, 'abilities' => $token->abilities])
                ->log('api_token.revoked');

            $token->delete();

            return true;
        });
    }
}

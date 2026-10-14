<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Auth\NotPartnerScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as BasePersonalAccessToken;

/**
 * Sanctum's token model with UUID v7 keys.
 *
 * Registered through Sanctum::usePersonalAccessTokenModel() in
 * ModelConventionsServiceProvider. The plain token has the form `<uuid>|<secret>`,
 * and the base model would cast that uuid prefix to an integer, so never
 * reference the base class.
 */
#[NotPartnerScoped(reason: 'token lookup runs before any user is authenticated')]
class PersonalAccessToken extends BasePersonalAccessToken
{
    use HasUuids;

    /**
     * Finds the token row of a plain text token, or null when the token cannot be one of ours.
     *
     * The base lookup passes the part before the pipe to a uuid primary key, and
     * PostgreSQL answers a value such as `abc` with SQLSTATE 22P02 (invalid input
     * syntax for type uuid), which would reach the client as a 500 instead of a 401.
     * A token whose id part is not a uuid is refused here before the database is asked.
     *
     * @param  string  $token
     */
    public static function findToken($token): ?static
    {
        if (str_contains($token, '|')) {
            [$id] = explode('|', $token, 2);

            if (! Str::isUuid($id)) {
                return null;
            }
        }

        return parent::findToken($token);
    }
}

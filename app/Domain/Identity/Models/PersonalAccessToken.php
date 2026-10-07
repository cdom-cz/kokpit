<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Auth\NotPartnerScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
}

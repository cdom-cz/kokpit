<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Auth\NotPartnerScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as BaseRole;

/**
 * The permission package's role with UUID v7 keys.
 *
 * Registered in config/permission.php. Using the package model directly would
 * cast the generated key to an integer, so never reference the base class.
 */
#[NotPartnerScoped(reason: 'role checks inside the scope load roles and permissions')]
class Role extends BaseRole
{
    use HasUuids;
}

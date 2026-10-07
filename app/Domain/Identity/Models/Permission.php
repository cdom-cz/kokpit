<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as BasePermission;

/**
 * The permission package's permission with UUID v7 keys.
 *
 * Registered in config/permission.php. Using the package model directly would
 * cast the generated key to an integer, so never reference the base class.
 */
class Permission extends BasePermission
{
    use HasUuids;
}

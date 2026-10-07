<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * The activity log model with UUID v7 keys.
 *
 * Registered in config/activitylog.php (`activity_model`). No model logs
 * activity yet; the attribute allowlist belongs to the audit phase.
 */
class Activity extends BaseActivity
{
    use HasUuids;
}

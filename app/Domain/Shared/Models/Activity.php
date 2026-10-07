<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * The activity log model with UUID v7 keys.
 *
 * Registered in config/activitylog.php (`activity_model`). No model logs
 * activity yet; the attribute allowlist belongs to the audit phase.
 *
 * Admin-only in Phase 2: a Partner sees no row (DeniesPartners). Later phases
 * open it deliberately with a client-bound constraint and explicit policy grants.
 */
class Activity extends BaseActivity implements PartnerIsolated
{
    use DeniesPartners, HasUuids;
}

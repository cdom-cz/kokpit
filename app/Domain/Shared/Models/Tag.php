<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Tags\Tag as BaseTag;

/**
 * The tags package model with UUID v7 keys.
 *
 * Registered in config/tags.php (`tag_model`). The package base model would
 * cast the generated key to an integer, so never reference it.
 *
 * Admin-only in Phase 2: a Partner sees no row (DeniesPartners). Later phases
 * open it deliberately with a client-bound constraint and explicit policy grants.
 */
class Tag extends BaseTag implements PartnerIsolated
{
    use DeniesPartners, HasUuids;
}

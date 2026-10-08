<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelSettings\Models\SettingsProperty as BaseSettingsProperty;

/**
 * The settings row model with UUID v7 keys.
 *
 * Registered in config/settings.php (`repositories.database.model`). Settings
 * are Admin and system data: a Partner sees no row, and a settings class
 * resolved in a Partner context fails closed instead of returning a value.
 *
 * PARTNER_VISIBLE_GROUPS is the only widening point. It is empty, so the rule
 * equals deny-all. A group added here becomes readable for every Partner with a
 * client; the canary test then fails until its author swaps DeniesPartners for
 * a real client-bound constraint and a matching fixture on purpose.
 */
class SettingsProperty extends BaseSettingsProperty implements PartnerIsolated
{
    use DeniesPartners, HasUuids {
        DeniesPartners::constrainForPartner as private denyAll;
    }

    /**
     * Settings groups a Partner may read.
     *
     * @var list<string>
     */
    public const array PARTNER_VISIBLE_GROUPS = [];

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        if (static::PARTNER_VISIBLE_GROUPS === []) {
            $this->denyAll($query, $clientId);

            return;
        }

        $query->whereIn('group', static::PARTNER_VISIBLE_GROUPS);
    }
}

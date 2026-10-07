<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * For Admin-only models: a Partner sees no row at all.
 *
 * The model still implements PartnerIsolated, so the PartnerScope is
 * registered and the Admin and system runs pass through it; the constraint for
 * a Partner is `WHERE 1 = 0`. Phases that open such a model to Partners replace
 * this trait with a real, client-bound constraint and a policy with explicit
 * grants.
 */
trait DeniesPartners
{
    use IsolatesPartners;

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query->whereRaw('1 = 0');
    }
}

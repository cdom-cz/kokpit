<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose rows a Partner may read, limited to the Partner's own client.
 *
 * There is deliberately no default implementation: every tenant model decides
 * how its rows belong to a client. A model that must stay closed to Partners
 * uses the DeniesPartners trait instead.
 */
interface PartnerIsolated
{
    /**
     * Restricts the query to the rows of the given client.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void;
}

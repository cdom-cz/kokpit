<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The test-only tenant model of the canary harness (D-04).
 *
 * Its table is created inside each test (PostgreSQL DDL is transactional, so
 * RefreshDatabase rolls it back) and no production code references it. A row
 * belongs to one client through `client_id`; `secret` carries a runtime canary
 * string that must never reach another client's Partner.
 *
 * @property string $id
 * @property string $client_id
 * @property string $secret
 */
#[UsePolicy(CanaryRecordPolicy::class)]
final class CanaryRecord extends KokpitModel implements PartnerIsolated
{
    use IsolatesPartners;

    protected $table = 'canary_records';

    protected $guarded = [];

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query->where($this->qualifyColumn('client_id'), $clientId);
    }
}

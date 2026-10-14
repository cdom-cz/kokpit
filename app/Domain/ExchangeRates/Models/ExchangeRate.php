<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;

/**
 * One CNB exchange rate: a currency on a published day, with the unit amount the CNB published it for.
 *
 * The data is public, but it is finance-like and closed to Partners like every rate: DeniesPartners and the
 * admin-only policy leave a Partner no row and no ability.
 *
 * The rate is numeric(20,10) and reads as a decimal string with ten places (`25.1250000000`), never a float;
 * it is the price of `unit_amount` units of the currency in CZK. The table has one row per currency and
 * published day, written once and never changed (a conflict does nothing), and non-publication days are never
 * stored: a weekend or holiday is resolved by looking up the newest earlier row.
 *
 * No attribute is fillable: rows are written only by insert-or-ignore (download, backfill, gap heal) and by
 * explicit test fixtures.
 *
 * @property string $id
 * @property CarbonImmutable $rate_date
 * @property string $currency
 * @property int $unit_amount
 * @property numeric-string $rate
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ExchangeRate extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    protected $table = 'exchange_rates';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_date' => 'immutable_date',
            'fetched_at' => 'immutable_datetime',
            'rate' => 'decimal:10',
            'unit_amount' => 'integer',
        ];
    }
}

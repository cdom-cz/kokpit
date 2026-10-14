<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\RateBook;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What an hour of a fixed-price project has actually earned (RE-05; D-07, D-08; UI-SPEC B4, U-3).
 *
 * The effective hourly rate is the agreed price over ALL worked hours of the whole project, not only the hours of
 * the report period (assumption A3): every entry of the project, billable or not, finished or running, at any
 * date, the figure of `TimeTotals::forProject()['worked']`. It is formed by `Money::hourlyRateFor()`, rounded once.
 *
 * The CZK figures use the CNB rate of the last day of the selected period capped at today (U-3, confirmed by the
 * owner), and the block prints that day. The converted price is the same arithmetic as
 * {@see ExchangeRateConverter::toCzk()}, with the lookup preloaded once for every currency of the block (one
 * {@see RateBook}), so the number of queries does not depend on the number of projects: one for the prices, one
 * for the whole-project seconds and the two of the rate book. The CZK rate is derived from the converted price
 * (`hourlyRateFor($priceCzk, $seconds)`), each figure rounded once.
 *
 * A project with no worked second has no rate (`hourlyRateFor()` refuses zero seconds): the rate and its CZK
 * figure are null and the view shows a dash. A currency without a CNB list leaves the CZK figures null and sets
 * `czk_missing`, so a missing rate is a visible marker and never a zero. A CZK price needs no conversion: its CZK
 * figures are null and `czk_missing` is false, and the view omits the CZK lines.
 *
 * The prices and the entries are read through the Admin-only tables, so only the Admin and a system run may ask.
 *
 * @phpstan-type Summary array{price: Money, price_czk: Money|null, project_seconds: int, effective_rate: Money|null, effective_rate_czk: Money|null, conversion_day: string, czk_missing: bool}
 */
final class FixedPriceSummary
{
    /**
     * The summary of every project of the list whose billing type is fixed price and that holds a price, keyed by
     * project id.
     *
     * @param  list<string>  $projectIds
     * @return array<string, Summary>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public static function for(array $projectIds, ReportFilters $filters, CarbonInterface $now): array
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The effective rate is available to the Admin only.');
        }

        if ($projectIds === []) {
            return [];
        }

        $prices = [];

        foreach (ProjectBilling::query()
            ->whereIn('project_id', $projectIds)
            ->where('billing_type', BillingType::FixedPrice->value)
            ->get() as $billing) {
            if ($billing->fixed_price instanceof Money) {
                $prices[$billing->project_id] = $billing->fixed_price;
            }
        }

        if ($prices === []) {
            return [];
        }

        $seconds = self::seconds(array_keys($prices), $now);

        $today = CarbonImmutable::instance($now)->setTimezone(FilamentTimezone::get())->format('Y-m-d');
        $day = min($filters->to, $today);

        $currencies = array_values(array_unique(array_filter(
            array_map(static fn (Money $price): string => $price->currency, $prices),
            static fn (string $currency): bool => $currency !== 'CZK',
        )));
        sort($currencies);
        $rates = RateBook::load($currencies, $day, $day);

        $summaries = [];

        foreach ($prices as $projectId => $price) {
            $worked = $seconds[$projectId] ?? 0;
            $rate = $worked > 0 ? Money::hourlyRateFor($price, $worked) : null;
            $priceCzk = null;
            $rateCzk = null;
            $missing = false;

            if ($price->currency !== 'CZK') {
                $quote = $rates->rateFor($price->currency, $day);

                if ($quote === null) {
                    $missing = true;
                } else {
                    $priceCzk = $price->convert($quote['rate'], 'CZK', $quote['unit_amount']);
                    $rateCzk = $worked > 0 ? Money::hourlyRateFor($priceCzk, $worked) : null;
                }
            }

            $summaries[$projectId] = [
                'price' => $price,
                'price_czk' => $priceCzk,
                'project_seconds' => $worked,
                'effective_rate' => $rate,
                'effective_rate_czk' => $rateCzk,
                'conversion_day' => $day,
                'czk_missing' => $missing,
            ];
        }

        return $summaries;
    }

    /**
     * The worked seconds of each project, in one grouped query: a finished entry with its stored duration, a
     * running one with the seconds from its start to the PHP clock instant, never negative.
     *
     * @param  list<string>  $projectIds
     * @return array<string, int>
     */
    private static function seconds(array $projectIds, CarbonInterface $now): array
    {
        $rows = TimeEntry::query()
            ->whereIn('time_entries.project_id', $projectIds)
            ->selectRaw(
                'time_entries.project_id AS project_id, COALESCE(SUM('.TimeEntry::elapsedSecondsSql('time_entries').'), 0) AS seconds',
                [$now->utc()->format('Y-m-d H:i:sP')],
            )
            ->groupBy('time_entries.project_id')
            ->toBase()
            ->get();

        $seconds = [];

        foreach ($rows as $row) {
            $seconds[(string) $row->project_id] = (int) $row->seconds;
        }

        return $seconds;
    }
}

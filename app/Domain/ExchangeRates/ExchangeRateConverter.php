<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates;

use App\Domain\Shared\Money\Money;

/**
 * Turns an amount in any currency on the CNB list into CZK at the rate of a day (EX-03, D-01, D-05).
 *
 * The rate of a day is the newest stored list not after it, so a weekend or holiday uses the previous valid
 * rate and an outage keeps using the last known one however old it is (D-04); staleness is the rates health
 * slot's business, not the converter's. A rate quoted per 100 or 1000 units is divided by its unit amount (D-02).
 * When no list exists for the currency on or before the day, {@see ExchangeRateMissing} is thrown and never a
 * zero amount.
 *
 * The converter adds no role check of its own: ExchangeRate is closed to Partners at the model scope, so a
 * Partner context finds no rate and gets {@see ExchangeRateMissing}. It is a service only; there is no
 * calculator screen (UI-SPEC U-7).
 */
final class ExchangeRateConverter
{
    private const string CZK = 'CZK';

    /**
     * The amount in CZK, rounded once by Money; a CZK amount is returned as it is, without a lookup.
     *
     * @param  string  $day  `YYYY-MM-DD`
     *
     * @throws ExchangeRateMissing when no list exists for the currency on or before the day
     */
    public function toCzk(Money $amount, string $day): Money
    {
        if ($amount->currency === self::CZK) {
            return $amount;
        }

        $rate = $this->rateFor($amount->currency, $day);

        return $amount->convert($rate['rate'], self::CZK, $rate['unit_amount']);
    }

    /**
     * The CZK amount of a duration at an hourly rate, rounded once from one exact rational (research Pitfall 6):
     * rounding the client-currency amount first and converting it afterwards can differ by a haléř per entry.
     * A CZK rate needs no lookup and is priced like any CZK duration.
     *
     * @param  string  $day  `YYYY-MM-DD`, the day the work belongs to
     *
     * @throws ExchangeRateMissing when no list exists for the currency on or before the day
     */
    public function durationToCzk(Money $hourlyRate, int $seconds, string $day): Money
    {
        if ($hourlyRate->currency === self::CZK) {
            return Money::forDuration($hourlyRate, $seconds);
        }

        $rate = $this->rateFor($hourlyRate->currency, $day);

        return Money::forDurationConverted($hourlyRate, $seconds, $rate['rate'], self::CZK, $rate['unit_amount']);
    }

    /**
     * The list that applies to a currency on a day: the rate, the unit amount it is quoted for and the day of
     * the list that was used.
     *
     * @param  string  $day  `YYYY-MM-DD`
     * @return array{rate: string, unit_amount: int, rate_date: string}
     *
     * @throws ExchangeRateMissing when no list exists for the currency on or before the day
     */
    public function rateFor(string $currency, string $day): array
    {
        $rate = RateBook::load([$currency], $day, $day)->rateFor($currency, $day);

        if ($rate === null) {
            throw new ExchangeRateMissing($currency, $day);
        }

        return $rate;
    }
}

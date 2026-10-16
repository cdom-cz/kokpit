<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The one rule that fixes the CZK figure of a transaction (D-13, EX-03).
 *
 * A CZK amount is its own figure (identity, no rate). A foreign amount takes the figure the Admin typed
 * (manual, no rate), then an own rate given for this one transaction (custom: the amount is converted at it and
 * the rate, the unit amount and the transaction date are stored as the snapshot) or, without either, the CNB list of the transaction date: the rate is looked up once with
 * `rateFor()` (the newest list not after the day, so a weekend uses Friday's) and the amount is converted
 * with exactly that rate and unit amount, so the rate, the unit amount and the list date that produced the
 * figure are what gets stored. The caller writes the result once; nothing ever looks the rate up again, so a
 * list added or corrected later cannot move a stored figure.
 */
final readonly class TransactionCzk
{
    public function __construct(private ExchangeRateConverter $converter) {}

    /**
     * @param  string  $day  `YYYY-MM-DD`, the transaction date
     * @param  Money|null  $manualCzk  the figure the Admin typed, in CZK; ignored for a CZK amount
     * @param  CustomRate|null  $customRate  an own rate for this transaction; ignored for a CZK amount and when a figure is typed
     * @return array{czk_minor: int, czk_source: CzkSource, exchange_rate: string|null, exchange_unit_amount: int|null, exchange_rate_date: string|null}
     *
     * @throws ValidationException `czk_amount` when a foreign amount has neither a figure, an own rate nor a CNB list;
     *                             `exchange_rate` when the own rate cannot convert; `amount` when the converted figure does
     *                             not fit
     */
    public function resolve(Money $amount, string $day, ?Money $manualCzk, ?CustomRate $customRate = null): array
    {
        if ($amount->currency === 'CZK') {
            return self::result($amount->minor, CzkSource::Identity);
        }

        if ($manualCzk instanceof Money) {
            if ($manualCzk->currency !== 'CZK') {
                throw new InvalidArgumentException('The manual figure must be in CZK.');
            }

            return self::result($manualCzk->minor, CzkSource::Manual);
        }

        if ($customRate instanceof CustomRate) {
            try {
                $czk = $amount->convert($customRate->rate, 'CZK', $customRate->unitAmount);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['exchange_rate' => __('finance.transactions.errors.rate_invalid')]);
            } catch (OverflowException) {
                throw ValidationException::withMessages(['amount' => __('finance.transactions.errors.amount_invalid')]);
            }

            return self::result($czk->minor, CzkSource::Custom, $customRate->rate, $customRate->unitAmount, $customRate->rateDate ?? $day);
        }

        try {
            $rate = $this->converter->rateFor($amount->currency, $day);
        } catch (ExchangeRateMissing) {
            throw ValidationException::withMessages([
                'czk_amount' => __('finance.transactions.errors.czk_required_missing_rate', [
                    'currency' => $amount->currency,
                    'date' => CarbonImmutable::parse($day)->format('j. n. Y'),
                ]),
            ]);
        }

        try {
            $czk = $amount->convert($rate['rate'], 'CZK', $rate['unit_amount']);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['amount' => __('finance.transactions.errors.amount_invalid')]);
        }

        return self::result($czk->minor, CzkSource::Cnb, $rate['rate'], $rate['unit_amount'], $rate['rate_date']);
    }

    /**
     * @return array{czk_minor: int, czk_source: CzkSource, exchange_rate: string|null, exchange_unit_amount: int|null, exchange_rate_date: string|null}
     */
    private static function result(int $minor, CzkSource $source, ?string $rate = null, ?int $unit = null, ?string $rateDate = null): array
    {
        return [
            'czk_minor' => $minor,
            'czk_source' => $source,
            'exchange_rate' => $rate,
            'exchange_unit_amount' => $unit,
            'exchange_rate_date' => $rateDate,
        ];
    }
}

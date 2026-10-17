<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\ExchangeRates\RateBook;
use App\Domain\Shared\Money\Money;

/**
 * What one time entry is worth: the amount in the currency of its rate and the amount in CZK (RE-02; D-01, D-07).
 *
 * Every figure of the phase (report, exports, dashboard) is a sum of these per-entry amounts, each rounded once,
 * so two screens can never differ by rounding (research Pattern 5). The amount is the exact seconds at the
 * effective hourly rate; the CZK amount is the same duration converted at the CNB rate of the Prague day the
 * entry started, as one exact value rounded once (`Money::forDurationConverted()`), not the rounded client amount
 * converted and rounded again (research Pitfall 6). A CZK entry is its own CZK amount and asks for no rate.
 *
 * An entry carries an amount only when its own billable flag is set, its effective billing type is not
 * `fixed_price` (whether the project, a task or a parent task says so; D-07, assumption A7: task-level fixed
 * prices show hours only) and a rate exists. Anything else has no amount and counts hours only. A day without
 * a CNB list before it has no CZK amount and is reported as `czk_missing`: it is a visible marker and never a zero.
 *
 * A billed entry that holds a billing snapshot (the rate and the amount it was billed at, TI-08) is priced with it:
 * the amount is the stored `billedAmount` and the CZK amount converts the same seconds at the snapshot rate, so a
 * later rate change never moves billed work (research Pitfall 7). An entry billed before invoicing existed has no
 * snapshot and an unbilled one never has: both are priced at the current effective rate.
 */
final class EntryAmounts
{
    /**
     * @return array{amount: Money|null, czk: Money|null, czk_missing: bool}
     */
    public static function of(ReportEntry $entry, RateBook $rates): array
    {
        if ($entry->billed && $entry->billedRate !== null && $entry->billedAmount !== null) {
            return self::converted($entry, $entry->billedRate, $entry->billedAmount, $rates);
        }

        $rate = $entry->rate;

        if (! $entry->billable || $entry->billingType === 'fixed_price' || $rate === null) {
            return ['amount' => null, 'czk' => null, 'czk_missing' => false];
        }

        return self::converted($entry, $rate, Money::forDuration($rate, $entry->seconds), $rates);
    }

    /**
     * The amount of the entry at the rate and its CZK counterpart at the CNB rate of the entry's day.
     *
     * @return array{amount: Money, czk: Money|null, czk_missing: bool}
     */
    private static function converted(ReportEntry $entry, Money $rate, Money $amount, RateBook $rates): array
    {
        if ($rate->currency === 'CZK') {
            return ['amount' => $amount, 'czk' => $amount, 'czk_missing' => false];
        }

        $quote = $rates->rateFor($rate->currency, $entry->workDate);

        if ($quote === null) {
            return ['amount' => $amount, 'czk' => null, 'czk_missing' => true];
        }

        return [
            'amount' => $amount,
            'czk' => Money::forDurationConverted($rate, $entry->seconds, $quote['rate'], 'CZK', $quote['unit_amount']),
            'czk_missing' => false,
        ];
    }
}

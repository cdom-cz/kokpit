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
 * Amounts are computed from the current rates until Phase 10 snapshots them on billing (research Pitfall 7).
 */
final class EntryAmounts
{
    /**
     * @return array{amount: Money|null, czk: Money|null, czk_missing: bool}
     */
    public static function of(ReportEntry $entry, RateBook $rates): array
    {
        $rate = $entry->rate;

        if (! $entry->billable || $entry->billingType === 'fixed_price' || $rate === null) {
            return ['amount' => null, 'czk' => null, 'czk_missing' => false];
        }

        $amount = Money::forDuration($rate, $entry->seconds);

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

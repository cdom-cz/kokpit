<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates;

use RuntimeException;

/**
 * No CNB list exists on or before the asked day for a currency (EX-03).
 *
 * The converter never turns a missing rate into a zero amount: the caller decides how to show the gap
 * (a visible marker in a report, a refusal when an invoice is issued). The message carries only the currency
 * and the day, both already known to the caller.
 */
final class ExchangeRateMissing extends RuntimeException
{
    public function __construct(
        public readonly string $currency,
        public readonly string $day,
    ) {
        parent::__construct(sprintf('No exchange rate for %s on or before %s.', $currency, $day));
    }
}

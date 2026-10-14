<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates;

use InvalidArgumentException;

/**
 * The single display rule for a CNB rate (UI-SPEC Typography, "CNB rates").
 *
 * A stored rate is a decimal string with ten places (`25.1250000000`). It is shown with a decimal comma, at least
 * three and at most ten decimals, the trailing zeros beyond the third trimmed (`25,125`, `24,300`, `0,0123456789`).
 * The digits are only ever cut at the string level, never rounded and never passed through a float, so the shown
 * value is exactly the stored one.
 */
final class RateFormat
{
    /** The fewest decimals a rate is shown with. */
    private const int MIN_DECIMALS = 3;

    /** The most decimals a rate is shown with, the scale of the stored column. */
    private const int MAX_DECIMALS = 10;

    /**
     * The rate as shown in a table: `25.1250000000` is `25,125`.
     *
     * @throws InvalidArgumentException when the value is not a plain decimal number
     */
    public static function display(string $decimal): string
    {
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/D', $decimal, $parts) !== 1) {
            throw new InvalidArgumentException('A rate must be a plain decimal number.');
        }

        $whole = $parts[1];
        $fraction = substr($parts[2] ?? '', 0, self::MAX_DECIMALS);

        // Trailing zeros go, but never below the minimum number of decimals.
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, self::MIN_DECIMALS, '0');

        return $whole.','.$fraction;
    }
}

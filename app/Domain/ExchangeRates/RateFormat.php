<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates;

use InvalidArgumentException;
use ResourceBundle;

/**
 * The single display rule for a CNB rate (UI-SPEC Typography, "CNB rates").
 *
 * A stored rate is a decimal string with ten places (`25.1250000000`). It is shown with a decimal comma, at least
 * three and at most ten decimals, the trailing zeros beyond the third trimmed (`25,125`, `24,300`, `0,0123456789`).
 * The digits are only ever cut at the string level, never rounded and never passed through a float, so the shown
 * value is exactly the stored one.
 *
 * The Czech name of a currency comes from the ICU data of ext-intl, so no name list is kept in the code.
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

    /**
     * The Czech name of an ISO 4217 currency (`EUR` is `euro`, `HUF` is `maďarský forint`), or null for a code ICU
     * has no name for. Only an upper-case three-letter code is looked up, so nothing else reaches the bundle.
     */
    public static function currencyName(string $code): ?string
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            return null;
        }

        $entry = self::currencies()?->get($code);

        if (! $entry instanceof ResourceBundle) {
            return null;
        }

        // Entry 0 is the symbol, entry 1 the display name.
        $name = $entry[1] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The Czech currency table of ICU, opened once per process.
     */
    private static function currencies(): ?ResourceBundle
    {
        static $currencies = false;

        if ($currencies === false) {
            $bundle = ResourceBundle::create('cs', 'ICUDATA-curr');
            $table = $bundle instanceof ResourceBundle ? $bundle['Currencies'] : null;
            $currencies = $table instanceof ResourceBundle ? $table : null;
        }

        return $currencies;
    }
}

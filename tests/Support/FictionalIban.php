<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Settings\Banking\Iban;

/**
 * Fictional IBANs for tests, composed at runtime from a repeated digit fragment
 * so no test file carries a complete account number (public repository,
 * fictional data only). The check digits are computed by Iban::compose().
 */
final class FictionalIban
{
    /**
     * A valid 24-character Czech IBAN.
     */
    public static function czech(string $fragment = '12'): string
    {
        return self::of('CZ', $fragment);
    }

    /**
     * A valid IBAN of any country in the Iban length table.
     */
    public static function of(string $country, string $fragment = '12'): string
    {
        $length = Iban::lengthFor($country);

        if ($length === null) {
            throw new \InvalidArgumentException('The IBAN country is not supported.');
        }

        return Iban::compose($country, substr(str_repeat($fragment, $length), 0, $length - 4));
    }
}

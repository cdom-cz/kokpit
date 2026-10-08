<?php

declare(strict_types=1);

namespace App\Domain\Settings\Banking;

use InvalidArgumentException;

/**
 * IBAN checks without a library (D-03): the country must be in the supported
 * table, the length must match that country, and the ISO 7064 mod-97 checksum
 * must be 1. Pure and static; callers normalise first.
 */
final class Iban
{
    /**
     * ISO 13616 IBAN lengths of the supported countries: Czech Republic,
     * Slovakia, the EU and EEA countries, Switzerland and the United Kingdom.
     *
     * @var array<string, int>
     */
    public const array LENGTHS = [
        'CZ' => 24,
        'SK' => 24,
        'AT' => 20,
        'BE' => 16,
        'BG' => 22,
        'HR' => 21,
        'CY' => 28,
        'DK' => 18,
        'EE' => 20,
        'FI' => 18,
        'FR' => 27,
        'DE' => 22,
        'GR' => 27,
        'HU' => 28,
        'IS' => 26,
        'IE' => 22,
        'IT' => 27,
        'LV' => 21,
        'LI' => 21,
        'LT' => 20,
        'LU' => 20,
        'MT' => 31,
        'NL' => 18,
        'NO' => 15,
        'PL' => 28,
        'PT' => 25,
        'RO' => 24,
        'SI' => 19,
        'ES' => 24,
        'SE' => 24,
        'CH' => 21,
        'GB' => 22,
    ];

    /**
     * Removes whitespace and upper-cases, the form an IBAN is checked and stored in.
     */
    public static function normalise(string $iban): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $iban));
    }

    /**
     * The expected IBAN length of a country, or null when the country is not supported.
     */
    public static function lengthFor(string $country): ?int
    {
        return self::LENGTHS[$country] ?? null;
    }

    /**
     * Whether a normalised IBAN is well formed: supported country, exact length,
     * two check digits, an alphanumeric BBAN and a mod-97 remainder of 1.
     */
    public static function isValid(string $iban): bool
    {
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/D', $iban) !== 1) {
            return false;
        }

        if (self::lengthFor(substr($iban, 0, 2)) !== strlen($iban)) {
            return false;
        }

        return self::mod97(substr($iban, 4).substr($iban, 0, 4)) === 1;
    }

    /**
     * Builds a valid IBAN from a country and a BBAN by computing the check digits.
     *
     * @throws InvalidArgumentException when the country is unsupported or the BBAN has the wrong length or characters
     */
    public static function compose(string $country, string $bban): string
    {
        $length = self::lengthFor($country);

        if ($length === null) {
            throw new InvalidArgumentException('The IBAN country is not supported.');
        }

        if (strlen($bban) !== $length - 4 || preg_match('/^[A-Z0-9]+$/D', $bban) !== 1) {
            throw new InvalidArgumentException('The BBAN length or characters do not match the country.');
        }

        $check = 98 - self::mod97($bban.$country.'00');

        return $country.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$bban;
    }

    /**
     * The remainder modulo 97 of an alphanumeric string read as a number with
     * A = 10 to Z = 35, computed in chunks so it never overflows an integer.
     */
    private static function mod97(string $rearranged): int
    {
        $digits = '';

        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_digit($character) ? $character : (string) (ord($character) - 55);
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}

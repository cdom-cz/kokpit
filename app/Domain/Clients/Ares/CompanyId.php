<?php

declare(strict_types=1);

namespace App\Domain\Clients\Ares;

/**
 * The Czech company number (IČO) check without a library (D-09): exactly eight
 * digits whose last digit is the mod-11 check digit of the first seven. Pure and
 * static; callers trim first, because a space is not a digit.
 */
final class CompanyId
{
    /**
     * Whether the text is a well-formed Czech company number: eight digits, the
     * first seven weighted 8 down to 2, the sum taken modulo 11, and a check
     * digit of 1 for remainder 0, 0 for remainder 1 and 11 minus the remainder
     * otherwise.
     */
    public static function isValid(string $id): bool
    {
        // The D modifier keeps a trailing newline from passing as the end of the text.
        if (preg_match('/^[0-9]{8}$/D', $id) !== 1) {
            return false;
        }

        $sum = 0;

        foreach (range(0, 6) as $position) {
            $sum += (int) $id[$position] * (8 - $position);
        }

        $remainder = $sum % 11;
        $check = match ($remainder) {
            0 => 1,
            1 => 0,
            default => 11 - $remainder,
        };

        return $check === (int) $id[7];
    }
}

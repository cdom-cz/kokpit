<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Fictional Czech company numbers for tests, generated at runtime so no test file
 * carries a real, checksum-valid value (public repository, fictional data only).
 * The check digit is computed here on its own, not through the class under test,
 * so a test that feeds these numbers to CompanyId is not a tautology.
 */
final class FictionalCompanyId
{
    /**
     * Seven random digits plus the computed check digit.
     */
    public static function valid(): string
    {
        return self::withCheckDigit(self::randomBody());
    }

    /**
     * A valid number whose seven-digit body has the given remainder modulo 11 of
     * the weighted digit sum; 0 and 1 are the two special check-digit cases.
     */
    public static function validWithRemainder(int $remainder): string
    {
        do {
            $body = self::randomBody();
        } while (self::weightedSum($body) % 11 !== $remainder);

        return self::withCheckDigit($body);
    }

    /**
     * Eight digits whose last digit is wrong for its body.
     */
    public static function invalid(): string
    {
        $body = self::randomBody();
        $wrong = (((int) substr(self::withCheckDigit($body), 7, 1)) + random_int(1, 9)) % 10;

        return $body.$wrong;
    }

    private static function randomBody(): string
    {
        $body = '';

        for ($position = 0; $position < 7; $position++) {
            $body .= (string) random_int(0, 9);
        }

        return $body;
    }

    private static function weightedSum(string $body): int
    {
        $sum = 0;

        for ($position = 0; $position < 7; $position++) {
            $sum += (int) $body[$position] * (8 - $position);
        }

        return $sum;
    }

    private static function withCheckDigit(string $body): string
    {
        $remainder = self::weightedSum($body) % 11;
        $check = $remainder === 0 ? 1 : ($remainder === 1 ? 0 : 11 - $remainder);

        return $body.$check;
    }
}

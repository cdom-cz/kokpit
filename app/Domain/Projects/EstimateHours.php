<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use InvalidArgumentException;

/**
 * Converts a project estimate typed in hours to whole seconds and back (PR-03).
 *
 * The form enters hours with at most two decimals ("1,5" or "40"); a hundredth
 * of an hour is exactly 36 seconds, so the conversion is exact. It uses integer
 * arithmetic only: the digits of the text are parsed with a regular expression
 * and combined as `hours * 3600 + hundredths * 36`. More than two decimals, a
 * negative sign, a grouping character, a space inside the number or any text is
 * an error and is never cut off.
 */
final class EstimateHours
{
    private const int SECONDS_PER_HOUR = 3600;

    private const int SECONDS_PER_HUNDREDTH = 36;

    /** The largest value of the integer column project_billing.estimate_seconds (about 596 523 hours). */
    public const int MAX_SECONDS = 2147483647;

    /** Twelve digits of hours times 3600 still fit a 64-bit integer; longer texts are refused before the multiplication. */
    private const int MAX_HOUR_DIGITS = 12;

    /**
     * @throws InvalidArgumentException when the text is not a non-negative number with at most two decimals
     */
    public static function toSeconds(string $hours): int
    {
        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/D', trim($hours), $match) !== 1) {
            throw new InvalidArgumentException('The estimate must be a non-negative number of hours with at most two decimals.');
        }

        $whole = ltrim($match[1], '0');

        if (strlen($whole) > self::MAX_HOUR_DIGITS) {
            throw new InvalidArgumentException('The estimate is too large.');
        }

        $fraction = str_pad($match[2] ?? '', 2, '0');

        $seconds = (int) $whole * self::SECONDS_PER_HOUR + (int) $fraction * self::SECONDS_PER_HUNDREDTH;

        if ($seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('The estimate is too large for the column that stores it.');
        }

        return $seconds;
    }

    /**
     * The hours of a stored estimate in the Czech notation, trailing zeros trimmed:
     * 5400 is "1,5", 36 is "0,01", 144000 is "40". Seconds that are not a whole
     * hundredth of an hour are shown to the nearest hundredth (half up, integers only).
     *
     * @throws InvalidArgumentException when the seconds are negative
     */
    public static function fromSeconds(int $seconds): string
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('The estimate must not be negative.');
        }

        $hours = intdiv($seconds, self::SECONDS_PER_HOUR);
        $hundredths = intdiv(($seconds % self::SECONDS_PER_HOUR) * 100 + self::SECONDS_PER_HOUR / 2, self::SECONDS_PER_HOUR);

        if ($hundredths === 100) {
            $hours++;
            $hundredths = 0;
        }

        $fraction = rtrim(str_pad((string) $hundredths, 2, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $hours : $hours.','.$fraction;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The Czech working-day calendar that decides on which days the CNB publishes a rate list (EX-01).
 *
 * A day is a string `Y-m-d`, like the date columns hold it; the class is pure and static and never reads the
 * clock. A working day is a weekday that is not a public holiday. The public holidays are the eleven fixed days
 * (FIXED_DAYS), Easter Monday and, since 2016, Good Friday. Easter Sunday comes from the anonymous Gregorian
 * algorithm, so the class needs no `ext-calendar`.
 *
 * Assumption A5 (research): the CNB publishes a list on every Czech working day and on no public holiday (checked
 * for 2023 to 2025). A new public holiday is one line in FIXED_DAYS, or a new Easter rule next to the two below.
 */
final class CzechPublicHolidays
{
    /**
     * The fixed public holidays as `m-d`: New Year (also the day of the restoration of the independent Czech state),
     * Labour Day, Victory Day, Saints Cyril and Methodius, Jan Hus Day, Statehood Day, Independent Czechoslovak State
     * Day, Struggle for Freedom and Democracy Day, Christmas Eve, Christmas Day and the second day of Christmas.
     */
    private const array FIXED_DAYS = [
        '01-01', '05-01', '05-08', '07-05', '07-06', '09-28', '10-28', '11-17', '12-24', '12-25', '12-26',
    ];

    /** Good Friday is a public holiday from this year on (Act 245/2000 Coll. as amended in 2016). */
    private const int GOOD_FRIDAY_FROM_YEAR = 2016;

    /** The longest run of consecutive non-working days is far below this; the loop is only a safety stop. */
    private const int MAX_LOOKBACK_DAYS = 31;

    /**
     * Monday to Friday, and not a public holiday.
     *
     * @throws InvalidArgumentException when `$day` is not a real day of the form `Y-m-d`
     */
    public static function isWorkingDay(string $day): bool
    {
        $date = self::parse($day);

        return (int) $date->format('N') <= 5 && ! self::isPublicHoliday($day);
    }

    /**
     * Whether the date is a public holiday, on a weekend as well as on a weekday.
     *
     * @throws InvalidArgumentException when `$day` is not a real day of the form `Y-m-d`
     */
    public static function isPublicHoliday(string $day): bool
    {
        $date = self::parse($day);
        $year = (int) $date->format('Y');

        if (in_array($date->format('m-d'), self::FIXED_DAYS, true)) {
            return true;
        }

        $easter = CarbonImmutable::createFromFormat('!Y-m-d', self::easterSunday($year), 'UTC');

        if ($easter === null) {
            return false;
        }

        if ($day === $easter->modify('+1 day')->format('Y-m-d')) {
            return true;
        }

        return $year >= self::GOOD_FRIDAY_FROM_YEAR && $day === $easter->modify('-2 days')->format('Y-m-d');
    }

    /**
     * The closest working day before `$day`.
     *
     * @throws InvalidArgumentException when `$day` is not a real day of the form `Y-m-d`, or no working day lies
     *                                  within a month before it
     */
    public static function previousWorkingDay(string $day): string
    {
        $cursor = self::parse($day);

        for ($i = 0; $i < self::MAX_LOOKBACK_DAYS; $i++) {
            $cursor = $cursor->modify('-1 day');

            if (self::isWorkingDay($cursor->format('Y-m-d'))) {
                return $cursor->format('Y-m-d');
            }
        }

        throw new InvalidArgumentException('No working day within a month before '.$day);
    }

    /**
     * Easter Sunday of the Gregorian calendar (anonymous Gregorian algorithm, Meeus/Jones/Butcher), as `Y-m-d`.
     */
    public static function easterSunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOfMonth = ($h + $l - 7 * $m + 114) % 31 + 1;

        return sprintf('%04d-%02d-%02d', $year, $month, $dayOfMonth);
    }

    /**
     * Date arithmetic runs in UTC on a date-only value, so a daylight-saving shift never moves a day.
     */
    private static function parse(string $day): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || (int) substr($day, 0, 4) < 1) {
            throw new InvalidArgumentException('Not a calendar day: '.$day);
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');

        // A date such as 2026-02-30 is rolled over by PHP; the round trip catches it.
        if ($date === null || $date->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException('Not a calendar day: '.$day);
        }

        return $date;
    }
}

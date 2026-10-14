<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Support;

use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use InvalidArgumentException;
use Throwable;

/**
 * The calendar day of the panel timezone (Europe/Prague) as a pair of UTC instants.
 *
 * One place for the day rule of the panel's period filter and of the REST API's date range (OD-5): a
 * day starts at its midnight and ends at the midnight after it, and the calendar arithmetic runs on
 * plain dates, so a 23- or 25-hour day cannot shift a boundary.
 *
 * A supported day is one whose two bounding midnights (its own and the one after it) are storable
 * instants, UTC years 1 to 9999 (`TimerClock::isStorable()`). `0001-01-01` is not one: Prague's
 * historic local mean time puts its midnight in UTC year 0. `9999-12-31` is one, although the midnight
 * after it is built from the day components and never from a formatted five-digit year.
 */
final class PragueDay
{
    /**
     * A real, supported calendar day as `Y-m-d`, or null for anything else.
     */
    public static function day(mixed $value): ?string
    {
        $day = self::calendar($value);

        if ($day === null || self::midnight($day) === null || self::midnight($day->addDay()) === null) {
            return null;
        }

        return $day->format('Y-m-d');
    }

    /**
     * The UTC instant at which the calendar day of `$day` begins in the panel timezone; null for no day
     * or for a midnight that is not a storable instant.
     */
    public static function midnight(?CarbonImmutable $day): ?CarbonImmutable
    {
        if (! $day instanceof CarbonImmutable) {
            return null;
        }

        // Built from the components: a formatted year of five digits would not parse again.
        $midnight = CarbonImmutable::create($day->year, $day->month, $day->day, 0, 0, 0, FilamentTimezone::get())?->utc();

        return $midnight instanceof CarbonImmutable && TimerClock::isStorable($midnight) ? $midnight : null;
    }

    /**
     * The first instant of the day `Y-m-d`.
     *
     * @throws InvalidArgumentException when `$day` is not a real calendar day
     */
    public static function start(string $day): CarbonImmutable
    {
        return self::midnight(self::plain($day))
            ?? throw new InvalidArgumentException('Not a calendar day: '.$day);
    }

    /**
     * The first instant after the day `Y-m-d`: the midnight of the following day.
     *
     * @throws InvalidArgumentException when `$day` is not a real calendar day
     */
    public static function endExclusive(string $day): CarbonImmutable
    {
        return self::midnight(self::plain($day)->addDay())
            ?? throw new InvalidArgumentException('Not a calendar day: '.$day);
    }

    private static function plain(string $day): CarbonImmutable
    {
        $plain = self::day($day) === null ? null : self::calendar($day);

        return $plain instanceof CarbonImmutable ? $plain : throw new InvalidArgumentException('Not a calendar day: '.$day);
    }

    /**
     * The plain UTC date of a strict `Y-m-d` string, whatever its range.
     */
    private static function calendar(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day : null;
    }
}

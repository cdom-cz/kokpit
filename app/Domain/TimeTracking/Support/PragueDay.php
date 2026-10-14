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
 */
final class PragueDay
{
    /**
     * A real calendar day as `Y-m-d`, or null for anything else.
     */
    public static function day(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * The UTC instant at which the calendar day of `$day` begins in the panel timezone.
     */
    public static function midnight(?CarbonImmutable $day): ?CarbonImmutable
    {
        if (! $day instanceof CarbonImmutable) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), FilamentTimezone::get())?->utc();
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
        $plain = self::day($day) === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');

        return $plain instanceof CarbonImmutable ? $plain : throw new InvalidArgumentException('Not a calendar day: '.$day);
    }
}

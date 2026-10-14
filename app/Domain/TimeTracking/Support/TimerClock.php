<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single clock of every time write.
 *
 * The time columns are timestamptz(0), which ROUNDS a fractional second: an
 * instant at 10:00:00.7 would be stored as 10:00:01 and a duration would
 * disagree with the second the user saw. Every writer therefore truncates
 * (never rounds) through this class before an instant reaches the database.
 */
final class TimerClock
{
    /**
     * The current instant, truncated to the whole second.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfSecond();
    }

    /**
     * Any instant, truncated to the whole second.
     */
    public static function truncate(CarbonInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->startOfSecond();
    }

    /**
     * Whether the instant may be written to a time column: its UTC year lies between 1 and 9999.
     *
     * PostgreSQL has no year 0 and rejects the year-0 literal Laravel writes (SQLSTATE 22008), and Carbon
     * cannot read back a five-digit year. A well-formed instant can still fall outside the range once it
     * is moved to UTC (`0001-01-01T00:00:00+02:00` is UTC year 0, `9999-12-31T23:59:59-12:00` is UTC year
     * 10000), so every instant written to a time column must pass this check.
     */
    public static function isStorable(CarbonInterface $value): bool
    {
        $year = CarbonImmutable::instance($value)->utc()->year;

        return $year >= 1 && $year <= 9999;
    }
}

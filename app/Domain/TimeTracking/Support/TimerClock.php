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
}

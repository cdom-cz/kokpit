<?php

declare(strict_types=1);

namespace App\Domain\Signal\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The calendar of the planner: every "which day is it" question is answered in Europe/Prague,
 * whatever the server zone, and a day is the string `YYYY-MM-DD` (what a PostgreSQL date column
 * holds), never an instant. Weeks are ISO weeks and start on Monday.
 *
 * Date arithmetic runs in UTC on a date-only value, so a daylight-saving shift never moves a day.
 * Only `today()` reads the clock, through Carbon, so tests freeze the day with Carbon::setTestNow().
 */
final class SignalCalendar
{
    public const string ZONE = 'Europe/Prague';

    /** Czech weekday names, Monday first. */
    private const array WEEKDAY_NAMES = ['pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];

    public static function today(): string
    {
        return CarbonImmutable::now(self::ZONE)->format('Y-m-d');
    }

    public static function tomorrow(): string
    {
        return self::addDays(self::today(), 1);
    }

    public static function yesterday(): string
    {
        return self::addDays(self::today(), -1);
    }

    public static function addDays(string $day, int $days): string
    {
        return self::parse($day)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    public static function isValidDay(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        // A date such as 2026-02-31 is rolled over by PHP; the round trip catches it.
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /**
     * The Monday of the week that contains the day.
     */
    public static function weekStart(string $day): string
    {
        return self::addDays($day, -self::weekdayIndex($day));
    }

    public static function currentWeekStart(): string
    {
        return self::weekStart(self::today());
    }

    public static function isWeekStart(string $value): bool
    {
        return self::isValidDay($value) && self::weekdayIndex($value) === 0;
    }

    /**
     * Monday = 0 ... Sunday = 6, the convention of the recurring weekdays.
     */
    public static function weekdayIndex(string $day): int
    {
        return (int) self::parse($day)->format('N') - 1;
    }

    public static function isWeekend(string $day): bool
    {
        return self::weekdayIndex($day) >= 5;
    }

    /**
     * Every day from $from to $to, both included; an empty list when $to is before $from.
     * Capped so a forged range cannot allocate without bound.
     *
     * @return list<string>
     */
    public static function range(string $from, string $to, int $max = 400): array
    {
        $days = [];
        $cursor = $from;

        for ($i = 0; $i < $max && $cursor <= $to; $i++) {
            $days[] = $cursor;
            $cursor = self::addDays($cursor, 1);
        }

        return $days;
    }

    /**
     * "12. 10. 2026".
     */
    public static function format(string $day): string
    {
        return self::parse($day)->format('j. n. Y');
    }

    /**
     * "pondělí 12. 10. 2026".
     */
    public static function formatLong(string $day): string
    {
        return self::WEEKDAY_NAMES[self::weekdayIndex($day)].' '.self::format($day);
    }

    private static function parse(string $day): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC')) ?: throw new InvalidArgumentException("Not a day: {$day}");
    }
}

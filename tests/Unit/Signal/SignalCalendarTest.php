<?php

declare(strict_types=1);

use App\Domain\Signal\Support\SignalCalendar;
use Carbon\CarbonImmutable;

/*
 * The calendar of the planner: Europe/Prague days as strings, ISO weeks from Monday, and
 * arithmetic that a daylight-saving shift cannot move. Every date is fictional.
 */

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('takes today in Prague, not in UTC', function (): void {
    // 23:30 UTC on 11 Oct is already 01:30 on 12 Oct in Prague (CEST, UTC+2).
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 23:30:00', 'UTC'));

    expect(SignalCalendar::today())->toBe('2026-10-12')
        ->and(SignalCalendar::tomorrow())->toBe('2026-10-13')
        ->and(SignalCalendar::yesterday())->toBe('2026-10-11');
});

it('adds days across the end of daylight saving time without moving the day', function (): void {
    // Summer time ends on 25 Oct 2026.
    expect(SignalCalendar::addDays('2026-10-24', 1))->toBe('2026-10-25')
        ->and(SignalCalendar::addDays('2026-10-25', 1))->toBe('2026-10-26')
        ->and(SignalCalendar::addDays('2026-03-01', -1))->toBe('2026-02-28')
        ->and(SignalCalendar::addDays('2026-12-31', 1))->toBe('2027-01-01');
});

it('accepts only real days', function (): void {
    expect(SignalCalendar::isValidDay('2026-10-12'))->toBeTrue()
        ->and(SignalCalendar::isValidDay('2026-02-31'))->toBeFalse()
        ->and(SignalCalendar::isValidDay('2026-2-3'))->toBeFalse()
        ->and(SignalCalendar::isValidDay('12. 10. 2026'))->toBeFalse()
        ->and(SignalCalendar::isValidDay(''))->toBeFalse();
});

it('starts the ISO week on Monday, also for a Sunday', function (): void {
    expect(SignalCalendar::weekStart('2026-10-12'))->toBe('2026-10-12') // Monday
        ->and(SignalCalendar::weekStart('2026-10-14'))->toBe('2026-10-12')
        ->and(SignalCalendar::weekStart('2026-10-18'))->toBe('2026-10-12') // Sunday
        ->and(SignalCalendar::weekStart('2026-10-19'))->toBe('2026-10-19')
        ->and(SignalCalendar::isWeekStart('2026-10-12'))->toBeTrue()
        ->and(SignalCalendar::isWeekStart('2026-10-13'))->toBeFalse()
        ->and(SignalCalendar::isWeekStart('nonsense'))->toBeFalse();
});

it('numbers the weekdays from Monday = 0 and knows the weekend', function (): void {
    expect(SignalCalendar::weekdayIndex('2026-10-12'))->toBe(0)
        ->and(SignalCalendar::weekdayIndex('2026-10-18'))->toBe(6)
        ->and(SignalCalendar::isWeekend('2026-10-16'))->toBeFalse() // Friday
        ->and(SignalCalendar::isWeekend('2026-10-17'))->toBeTrue()
        ->and(SignalCalendar::isWeekend('2026-10-18'))->toBeTrue();
});

it('lists the days of a range, both ends included, and nothing for a reversed one', function (): void {
    expect(SignalCalendar::range('2026-10-30', '2026-11-02'))->toBe(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'])
        ->and(SignalCalendar::range('2026-11-02', '2026-10-30'))->toBe([])
        ->and(SignalCalendar::range('2026-01-01', '2030-01-01', 10))->toHaveCount(10);
});

it('formats days the Czech way', function (): void {
    expect(SignalCalendar::format('2026-10-05'))->toBe('5. 10. 2026')
        ->and(SignalCalendar::formatLong('2026-10-12'))->toBe('pondělí 12. 10. 2026');
});

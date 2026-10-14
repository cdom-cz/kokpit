<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\CzechPublicHolidays;
use Carbon\CarbonImmutable;

/*
 * The Czech working-day calendar the CNB rhythm depends on (EX-01): a day is a string `Y-m-d`, Easter comes from the
 * anonymous Gregorian algorithm, Good Friday is a holiday from 2016. Every date is a calendar fact, none is data.
 */

it('computes Easter Sunday of the Gregorian calendar', function (int $year, string $easter): void {
    expect(CzechPublicHolidays::easterSunday($year))->toBe($easter);
})->with([
    'a late March Easter' => [2024, '2024-03-31'],
    'a mid April Easter' => [2025, '2025-04-20'],
    'an early April Easter' => [2026, '2026-04-05'],
    'the earliest March Easter of the decade' => [2027, '2027-03-28'],
    'a late April Easter' => [2038, '2038-04-25'],
    'an old Easter' => [2015, '2015-04-05'],
]);

it('knows the Good Friday and the Easter Monday as public holidays', function (string $day): void {
    expect(CzechPublicHolidays::isPublicHoliday($day))->toBeTrue()
        ->and(CzechPublicHolidays::isWorkingDay($day))->toBeFalse();
})->with(['2025-04-18', '2025-04-21', '2026-04-03', '2026-04-06', '2016-03-25', '2016-03-28']);

it('knows every fixed public holiday of a year', function (string $day): void {
    expect(CzechPublicHolidays::isPublicHoliday($day))->toBeTrue();
})->with([
    '2026-01-01', '2026-05-01', '2026-05-08', '2026-07-05', '2026-07-06', '2026-09-28',
    '2026-10-28', '2026-11-17', '2026-12-24', '2026-12-25', '2026-12-26',
]);

it('does not treat Good Friday before 2016 as a holiday', function (): void {
    // 3 April 2015 was a Friday before Easter and still a working day.
    expect(CzechPublicHolidays::isPublicHoliday('2015-04-03'))->toBeFalse()
        ->and(CzechPublicHolidays::isWorkingDay('2015-04-03'))->toBeTrue()
        // Easter Monday was a holiday long before.
        ->and(CzechPublicHolidays::isPublicHoliday('2015-04-06'))->toBeTrue();
});

it('treats an ordinary weekday as a working day', function (string $day): void {
    expect(CzechPublicHolidays::isPublicHoliday($day))->toBeFalse()
        ->and(CzechPublicHolidays::isWorkingDay($day))->toBeTrue();
})->with(['2026-10-14', '2026-12-23', '2026-10-12', '2026-04-02', '2026-04-07']);

it('is not a working day on a Saturday or a Sunday', function (string $day): void {
    expect(CzechPublicHolidays::isWorkingDay($day))->toBeFalse();
})->with(['2026-10-10', '2026-10-11', '2026-10-17', '2026-10-18', '2027-01-02', '2027-01-03']);

it('is a public holiday on a weekend only when the date itself is one', function (): void {
    // 2026-07-05 is a Sunday and a fixed holiday; 2026-10-10 is a plain Saturday.
    expect(CzechPublicHolidays::isPublicHoliday('2026-07-05'))->toBeTrue()
        ->and(CzechPublicHolidays::isPublicHoliday('2026-10-10'))->toBeFalse();
});

it('finds the previous working day across weekends and holiday runs', function (string $day, string $previous): void {
    expect(CzechPublicHolidays::previousWorkingDay($day))->toBe($previous);
})->with([
    'over the Christmas holidays' => ['2026-12-28', '2026-12-23'],
    'over Easter' => ['2026-04-07', '2026-04-02'],
    'over a plain weekend' => ['2026-10-12', '2026-10-09'],
    'from a Sunday' => ['2026-10-11', '2026-10-09'],
    'from a midweek day' => ['2026-10-14', '2026-10-13'],
    'over the new year' => ['2027-01-04', '2026-12-31'],
]);

it('refuses a malformed day', function (string $day): void {
    expect(fn () => CzechPublicHolidays::isWorkingDay($day))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CzechPublicHolidays::isPublicHoliday($day))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CzechPublicHolidays::previousWorkingDay($day))->toThrow(InvalidArgumentException::class);
})->with(['2026-02-30', '14.10.2026', '', '2026-1-5', '2026-10-14 10:00:00', '0000-01-01', 'garbage']);

it('counts the working days after one day up to and including another', function (string $after, string $upTo, int $count): void {
    expect(CzechPublicHolidays::workingDaysBetween($after, $upTo))->toBe($count);
})->with([
    'over a weekend' => ['2026-10-09', '2026-10-14', 3],
    'from a Friday to the next Monday' => ['2026-10-09', '2026-10-12', 1],
    'the same day' => ['2026-10-14', '2026-10-14', 0],
    'a later start than the end' => ['2026-10-14', '2026-10-13', 0],
    'over Easter' => ['2026-04-02', '2026-04-07', 1],
    'over the Christmas holidays' => ['2026-12-23', '2026-12-31', 4],
]);

it('lists the working days of an inclusive range in order', function (): void {
    expect(CzechPublicHolidays::workingDays('2026-12-23', '2026-12-31'))
        ->toBe(['2026-12-23', '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31'])
        ->and(CzechPublicHolidays::workingDays('2026-10-10', '2026-10-11'))->toBe([])
        ->and(CzechPublicHolidays::workingDays('2026-10-14', '2026-10-13'))->toBe([]);
});

it('expects today list from the final hour on a working day and the previous working day otherwise', function (string $local, string $expected): void {
    expect(CzechPublicHolidays::expectedListDate(CarbonImmutable::parse($local, 'Europe/Prague'), 18))->toBe($expected);
})->with([
    'a working day before the final hour' => ['2026-10-14 17:59:00', '2026-10-13'],
    'a working day at the final hour' => ['2026-10-14 18:00:00', '2026-10-14'],
    'a working day late in the evening' => ['2026-10-14 23:30:00', '2026-10-14'],
    'a Saturday morning' => ['2026-10-10 08:00:00', '2026-10-09'],
    'a Saturday evening' => ['2026-10-10 20:00:00', '2026-10-09'],
    'a Sunday evening' => ['2026-10-11 20:00:00', '2026-10-09'],
    'Easter Monday evening' => ['2026-04-06 20:00:00', '2026-04-02'],
    'a Monday morning' => ['2026-10-12 09:00:00', '2026-10-09'],
]);

it('reads today in Prague time whatever the zone of the clock is', function (): void {
    // 22:30 UTC on 14 October is 00:30 on 15 October in Prague (summer time): a Thursday morning.
    expect(CzechPublicHolidays::expectedListDate(CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC'), 18))->toBe('2026-10-14')
        // 16:30 UTC is 18:30 in Prague: after the final hour.
        ->and(CzechPublicHolidays::expectedListDate(CarbonImmutable::parse('2026-10-14 16:30:00', 'UTC'), 18))->toBe('2026-10-14')
        // 15:30 UTC is 17:30 in Prague: before the final hour.
        ->and(CzechPublicHolidays::expectedListDate(CarbonImmutable::parse('2026-10-14 15:30:00', 'UTC'), 18))->toBe('2026-10-13');
});

it('refuses a malformed day in the range helpers', function (): void {
    expect(fn () => CzechPublicHolidays::workingDaysBetween('2026-02-30', '2026-03-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CzechPublicHolidays::workingDays('2026-10-14', 'garbage'))->toThrow(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

use App\Domain\TimeTracking\Support\DurationFormat;

/*
 * The single duration display rule (UI-SPEC Duration display): H:MM truncated to
 * whole minutes, H:MM:SS for the running readout and the view page, unbounded
 * hours, a minus sign for a negative value. Pure integer arithmetic.
 */

it('formats hours and minutes, truncating and never rounding up', function (int $seconds, string $expected): void {
    expect(DurationFormat::hoursMinutes($seconds))->toBe($expected);
})->with([
    'zero' => [0, '0:00'],
    'under a minute' => [59, '0:00'],
    'one minute' => [60, '0:01'],
    'one second short of an hour' => [3599, '0:59'],
    'one hour' => [3600, '1:00'],
    'one hour 25 minutes 29 seconds' => [5129, '1:25'],
    'hours beyond a day are not wrapped' => [137107, '38:05'],
    'a hundred hours' => [360000, '100:00'],
    'negative keeps the sign' => [-3900, '-1:05'],
    'negative under a minute' => [-59, '0:00'],
    'negative one minute' => [-60, '-0:01'],
]);

it('formats hours, minutes and seconds', function (int $seconds, string $expected): void {
    expect(DurationFormat::hoursMinutesSeconds($seconds))->toBe($expected);
})->with([
    'zero' => [0, '0:00:00'],
    'seven minutes 42 seconds' => [462, '0:07:42'],
    'one hour 25 minutes 29 seconds' => [5129, '1:25:29'],
    'twelve hours' => [43509, '12:05:09'],
    'hours beyond a day are not wrapped' => [445507, '123:45:07'],
    'one second' => [1, '0:00:01'],
    'negative keeps the sign' => [-462, '-0:07:42'],
]);

it('forms a sum from seconds first, so rows of 0:00 can add up to 0:01', function (): void {
    expect(DurationFormat::hoursMinutes(59))->toBe('0:00')
        ->and(DurationFormat::hoursMinutes(59 + 59))->toBe('0:01');
});

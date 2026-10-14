<?php

declare(strict_types=1);

use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonImmutable;

/*
 * OD-5: the Prague calendar day bounds shared by the panel's period filter and the REST API. The day
 * arithmetic runs on plain dates, so a 23- or 25-hour day cannot shift a boundary.
 */

it('starts a day at its Prague midnight as a UTC instant', function (string $day, string $expected): void {
    expect(PragueDay::start($day)->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($expected);
})->with([
    'ordinary winter day' => ['2026-01-15', '2026-01-14T23:00:00Z'],
    'ordinary summer day' => ['2026-07-15', '2026-07-14T22:00:00Z'],
    'the 25-hour autumn day' => ['2026-10-25', '2026-10-24T22:00:00Z'],
    'the day after it' => ['2026-10-26', '2026-10-25T23:00:00Z'],
    'the 23-hour spring day' => ['2027-03-28', '2027-03-27T23:00:00Z'],
]);

it('ends a day at the midnight after it, exclusive', function (string $day, string $expected): void {
    expect(PragueDay::endExclusive($day)->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($expected);
})->with([
    'ordinary winter day' => ['2026-01-15', '2026-01-15T23:00:00Z'],
    'the 25-hour autumn day' => ['2026-10-25', '2026-10-25T23:00:00Z'],
    'the 23-hour spring day' => ['2027-03-28', '2027-03-28T22:00:00Z'],
    'the last day of a year' => ['2026-12-31', '2026-12-31T23:00:00Z'],
]);

it('makes the 25-hour day exactly 25 hours long and the 23-hour day 23', function (): void {
    expect(PragueDay::start('2026-10-25')->diffInHours(PragueDay::endExclusive('2026-10-25')))->toBe(25.0)
        ->and(PragueDay::start('2027-03-28')->diffInHours(PragueDay::endExclusive('2027-03-28')))->toBe(23.0);
});

it('accepts only a real calendar day as Y-m-d', function (mixed $value, ?string $expected): void {
    expect(PragueDay::day($value))->toBe($expected);
})->with([
    'a real day' => ['2026-10-25', '2026-10-25'],
    'a leap day' => ['2028-02-29', '2028-02-29'],
    'a non-leap February 29' => ['2026-02-29', null],
    'February 30' => ['2026-02-30', null],
    'an instant' => ['2026-10-25T00:00:00+02:00', null],
    'a day without zero padding' => ['2026-1-5', null],
    'an empty string' => ['', null],
    'null' => [null, null],
    'an integer' => [2026, null],
]);

it('turns a plain day into the UTC instant of its Prague midnight', function (): void {
    $day = CarbonImmutable::createFromFormat('!Y-m-d', '2026-10-25', 'UTC');

    expect(PragueDay::midnight($day)?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-10-24T22:00:00Z')
        ->and(PragueDay::midnight(null))->toBeNull();
});

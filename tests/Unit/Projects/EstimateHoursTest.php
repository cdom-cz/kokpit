<?php

declare(strict_types=1);

use App\Domain\Projects\EstimateHours;

/*
 * The estimate is entered as hours with at most two decimals and stored as whole
 * seconds (PR-03 precision and boundary edges).
 */

it('converts hours to exact whole seconds', function (string $hours, int $seconds): void {
    expect(EstimateHours::toSeconds($hours))->toBe($seconds);
})->with([
    'decimal comma' => ['1,5', 5400],
    'decimal point' => ['1.5', 5400],
    'one hundredth of an hour' => ['0,01', 36],
    'zero' => ['0', 0],
    'whole hours' => ['40', 144000],
    'one decimal' => ['0,1', 360],
    'two decimals' => ['12,34', 44424],
    'leading zeros' => ['007,50', 27000],
    'padded by spaces at the edges' => [' 2 ', 7200],
    'largest estimate the column holds' => ['596523,23', 2147483628],
]);

it('refuses what is not a non-negative number with at most two decimals', function (string $hours): void {
    expect(fn () => EstimateHours::toSeconds($hours))->toThrow(InvalidArgumentException::class);
})->with([
    'three decimals' => ['1,333'],
    'negative' => ['-1'],
    'text' => ['abc'],
    'empty' => [''],
    'grouping space' => ['1 000'],
    'grouping comma' => ['1,000,5'],
    'exponent' => ['1e3'],
    'trailing separator' => ['1,'],
    'leading separator' => [',5'],
    'too many digits' => ['1000000000000'],
    'one hundredth above the column range' => ['596523,24'],
    'above the column range' => ['600000'],
]);

it('formats stored seconds as Czech hours with trailing zeros trimmed', function (int $seconds, string $hours): void {
    expect(EstimateHours::fromSeconds($seconds))->toBe($hours);
})->with([
    [5400, '1,5'],
    [36, '0,01'],
    [0, '0'],
    [144000, '40'],
    [44424, '12,34'],
    [360, '0,1'],
    // Not a whole hundredth: shown to the nearest hundredth, half up.
    [18, '0,01'],
    [17, '0'],
    [3599, '1'],
]);

it('refuses negative seconds', function (): void {
    expect(fn () => EstimateHours::fromSeconds(-1))->toThrow(InvalidArgumentException::class);
});

it('round-trips every hundredth of an hour in a day exactly', function (): void {
    for ($hundredths = 0; $hundredths <= 2400; $hundredths++) {
        $text = intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
        $seconds = EstimateHours::toSeconds($text);

        expect($seconds)->toBe($hundredths * 36)
            ->and(EstimateHours::toSeconds(EstimateHours::fromSeconds($seconds)))->toBe($seconds);
    }
});

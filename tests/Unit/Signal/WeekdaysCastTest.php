<?php

declare(strict_types=1);

use App\Domain\Signal\Casts\WeekdaysCast;

/*
 * The cast between the smallint[] column of a recurring template and a list of weekday indexes.
 */

it('reads the array literal of PostgreSQL as a list of integers', function (): void {
    expect(WeekdaysCast::parse('{0,2,4}'))->toBe([0, 2, 4])
        ->and(WeekdaysCast::parse('{6}'))->toBe([6])
        ->and(WeekdaysCast::parse('{}'))->toBe([])
        ->and(WeekdaysCast::parse('{ 1, 3 }'))->toBe([1, 3]);
});

it('writes a sorted list without duplicates and accepts numeric strings', function (): void {
    expect(WeekdaysCast::format([4, 0, 2]))->toBe('{0,2,4}')
        ->and(WeekdaysCast::format(['2', 2, 0]))->toBe('{0,2}')
        ->and(WeekdaysCast::format([]))->toBe('{}');
});

it('refuses an index that is no weekday', function (): void {
    expect(fn () => WeekdaysCast::format([7]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WeekdaysCast::format([-1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WeekdaysCast::format(['monday']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => WeekdaysCast::format([1.5]))->toThrow(InvalidArgumentException::class);
});

<?php

declare(strict_types=1);

use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalStats;

/*
 * The overview figures: progress by colour, daily trends, planned against extra and the streak of
 * days with every main task done. Plain rows in, plain numbers out.
 */

/**
 * @return array{for_date: string, category: string, is_done: bool}
 */
function signalRow(string $day, string $category, bool $done): array
{
    return ['for_date' => $day, 'category' => $category, 'is_done' => $done];
}

it('counts done and total per colour', function (): void {
    $progress = SignalStats::categoryProgress([
        signalRow('2026-10-12', 'main', true),
        signalRow('2026-10-12', 'main', false),
        signalRow('2026-10-12', 'other', true),
        signalRow('2026-10-12', 'extra', false),
    ]);

    expect($progress['main'])->toBe(['done' => 1, 'total' => 2])
        ->and($progress['medium'])->toBe(['done' => 0, 'total' => 0])
        ->and($progress['other'])->toBe(['done' => 1, 'total' => 1])
        ->and($progress['extra'])->toBe(['done' => 0, 'total' => 1])
        ->and(SignalStats::totalProgress([signalRow('2026-10-12', 'main', true), signalRow('2026-10-12', 'other', false)]))->toBe(['done' => 1, 'total' => 2]);
});

it('builds a completion trend with an empty day for every day without tasks', function (): void {
    $trend = SignalStats::dailyTrend([
        signalRow('2026-10-12', 'main', true),
        signalRow('2026-10-12', 'main', false),
        signalRow('2026-10-14', 'other', true),
    ], '2026-10-12', '2026-10-14');

    expect($trend)->toHaveCount(3)
        ->and($trend[0])->toBe(['date' => '2026-10-12', 'done' => 1, 'total' => 2, 'ratio' => 0.5])
        ->and($trend[1])->toBe(['date' => '2026-10-13', 'done' => 0, 'total' => 0, 'ratio' => 0.0])
        ->and($trend[2])->toBe(['date' => '2026-10-14', 'done' => 1, 'total' => 1, 'ratio' => 1.0]);
});

it('takes the planned blocks of a day without a row from the settings', function (): void {
    $trend = SignalStats::deepWorkTrend(
        ['2026-10-16' => ['planned' => 4, 'completed' => 2]],
        3,
        0,
        '2026-10-15',
        '2026-10-17',
    );

    expect($trend[0])->toBe(['date' => '2026-10-15', 'done' => 0, 'total' => 3, 'ratio' => 0.0]) // Thursday
        ->and($trend[1])->toBe(['date' => '2026-10-16', 'done' => 2, 'total' => 4, 'ratio' => 0.5]) // stored plan wins
        ->and($trend[2])->toBe(['date' => '2026-10-17', 'done' => 0, 'total' => 0, 'ratio' => 0.0]); // free Saturday
});

it('splits planned from extra tasks', function (): void {
    expect(SignalStats::plannedVsExtra([
        signalRow('2026-10-12', 'main', true),
        signalRow('2026-10-12', 'other', false),
        signalRow('2026-10-12', 'extra', false),
    ]))->toBe(['planned' => 2, 'extra' => 1, 'total' => 3]);
});

it('counts the streak of days with every main task done, ending today when it qualifies', function (): void {
    $rows = [
        signalRow('2026-10-10', 'main', true),
        signalRow('2026-10-11', 'main', true),
        signalRow('2026-10-11', 'main', true),
        signalRow('2026-10-12', 'main', true),
    ];

    expect(SignalStats::mainStreak($rows, '2026-10-12'))->toBe(3);
});

it('does not let a day in progress reset the streak', function (): void {
    $rows = [
        signalRow('2026-10-10', 'main', true),
        signalRow('2026-10-11', 'main', true),
        signalRow('2026-10-12', 'main', true),
        signalRow('2026-10-12', 'main', false),
    ];

    expect(SignalStats::mainStreak($rows, '2026-10-12'))->toBe(2);
});

it('breaks the streak at a day with an open main task or without main tasks', function (): void {
    expect(SignalStats::mainStreak([
        signalRow('2026-10-09', 'main', true),
        signalRow('2026-10-10', 'main', false),
        signalRow('2026-10-11', 'main', true),
    ], '2026-10-12'))->toBe(1)
        ->and(SignalStats::mainStreak([
            signalRow('2026-10-09', 'main', true),
            signalRow('2026-10-11', 'main', true),
        ], '2026-10-12'))->toBe(1)
        ->and(SignalStats::mainStreak([signalRow('2026-10-11', 'other', true)], '2026-10-12'))->toBe(0)
        ->and(SignalStats::mainStreak([], '2026-10-12'))->toBe(0);
});

it('counts a streak up to the shared cap and stops there', function (): void {
    $rows = [];

    foreach (range(0, SignalStats::MAX_STREAK_DAYS + 20) as $back) {
        $rows[] = signalRow(SignalCalendar::addDays('2026-10-12', -$back), 'main', true);
    }

    expect(SignalStats::mainStreak(array_slice($rows, 0, 45), '2026-10-12'))->toBe(45)
        ->and(SignalStats::mainStreak($rows, '2026-10-12'))->toBe(SignalStats::MAX_STREAK_DAYS)
        ->and(SignalStats::MAX_STREAK_DAYS)->toBe(SignalCalendar::MAX_RANGE_DAYS);
});

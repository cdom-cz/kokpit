<?php

declare(strict_types=1);

use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Support\SignalRules;

/*
 * The pure rules of the planner: what is grey, what fits the day, when a day is locked and what may
 * still be ticked on it. Every date is fictional.
 */

it('makes a task added on or after its day grey, and a planned one keep its colour', function (): void {
    expect(SignalRules::isExtra('2026-10-12', '2026-10-12'))->toBeTrue()
        ->and(SignalRules::isExtra('2026-10-12', '2026-10-13'))->toBeTrue()
        ->and(SignalRules::isExtra('2026-10-13', '2026-10-12'))->toBeFalse()
        ->and(SignalRules::resolveNewTaskCategory('2026-10-12', SignalCategory::Main, '2026-10-12'))->toBe(SignalCategory::Extra)
        ->and(SignalRules::resolveNewTaskCategory('2026-10-13', SignalCategory::Medium, '2026-10-12'))->toBe(SignalCategory::Medium)
        ->and(SignalRules::resolveNewTaskCategory('2026-10-13', null, '2026-10-12'))->toBeNull()
        ->and(SignalRules::resolveNewTaskCategory('2026-10-13', SignalCategory::Extra, '2026-10-12'))->toBeNull();
});

it('limits main and medium to three a day and leaves the rest unlimited', function (): void {
    expect(SignalRules::canAdd(SignalCategory::Main, 2))->toBeTrue()
        ->and(SignalRules::canAdd(SignalCategory::Main, 3))->toBeFalse()
        ->and(SignalRules::canAdd(SignalCategory::Medium, 3))->toBeFalse()
        ->and(SignalRules::canAdd(SignalCategory::Other, 50))->toBeTrue()
        ->and(SignalRules::canAdd(SignalCategory::Extra, 50))->toBeTrue();
});

it('reserves a slot for every recurring template of the colour that fires on the day', function (): void {
    expect(SignalRules::effectiveLimit(SignalCategory::Main, 0))->toBe(3)
        ->and(SignalRules::effectiveLimit(SignalCategory::Main, 2))->toBe(1)
        ->and(SignalRules::effectiveLimit(SignalCategory::Main, 5))->toBe(0)
        ->and(SignalRules::effectiveLimit(SignalCategory::Other, 5))->toBeNull()
        ->and(SignalRules::canAdd(SignalCategory::Main, 0, 2))->toBeTrue()
        ->and(SignalRules::canAdd(SignalCategory::Main, 1, 2))->toBeFalse()
        ->and(SignalRules::canAdd(SignalCategory::Main, 0, 3))->toBeFalse();
});

it('locks a past day unless it was unlocked, and never today or the future', function (): void {
    expect(SignalRules::isDayLocked('2026-10-11', false, '2026-10-12'))->toBeTrue()
        ->and(SignalRules::isDayLocked('2026-10-11', true, '2026-10-12'))->toBeFalse()
        ->and(SignalRules::isDayLocked('2026-10-12', false, '2026-10-12'))->toBeFalse()
        ->and(SignalRules::isDayLocked('2026-10-13', false, '2026-10-12'))->toBeFalse()
        ->and(SignalRules::canEditDay('2026-10-11', false, '2026-10-12'))->toBeFalse()
        ->and(SignalRules::canEditDay('2026-10-11', true, '2026-10-12'))->toBeTrue();
});

it('lets a task be ticked today and on past days, even locked ones, never in the future', function (): void {
    expect(SignalRules::canToggleDone('2026-10-10', '2026-10-12'))->toBeTrue()
        ->and(SignalRules::canToggleDone('2026-10-12', '2026-10-12'))->toBeTrue()
        ->and(SignalRules::canToggleDone('2026-10-13', '2026-10-12'))->toBeFalse();
});

it('tells which days a template fires on, Monday being index 0', function (): void {
    expect(SignalRules::firesOn([0, 4], '2026-10-12'))->toBeTrue() // Monday
        ->and(SignalRules::firesOn([0, 4], '2026-10-13'))->toBeFalse()
        ->and(SignalRules::firesOn([0, 4], '2026-10-16'))->toBeTrue() // Friday
        ->and(SignalRules::firesOn([0, 4], '2026-10-18'))->toBeFalse() // Sunday
        ->and(SignalRules::firesOn([], '2026-10-12'))->toBeFalse();
});

it('plans deep-work blocks by weekday or weekend and clamps what is completed', function (): void {
    expect(SignalRules::plannedBlocks('2026-10-16', 3, 1))->toBe(3) // Friday
        ->and(SignalRules::plannedBlocks('2026-10-17', 3, 1))->toBe(1) // Saturday
        ->and(SignalRules::clampCompleted(5, 3))->toBe(3)
        ->and(SignalRules::clampCompleted(-2, 3))->toBe(0)
        ->and(SignalRules::clampCompleted(2, 3))->toBe(2)
        ->and(SignalRules::clampCompleted(2, 0))->toBe(0);
});

it('declares the plannable colours, their limits and their sort order', function (): void {
    expect(SignalCategory::plannable())->toBe([SignalCategory::Main, SignalCategory::Medium, SignalCategory::Other])
        ->and(SignalCategory::Extra->isPlannable())->toBeFalse()
        ->and(SignalCategory::Main->dailyLimit())->toBe(3)
        ->and(SignalCategory::Other->dailyLimit())->toBeNull()
        ->and(array_map(static fn (SignalCategory $category): int => $category->rank(), SignalCategory::cases()))->toBe([0, 1, 2, 3]);
});

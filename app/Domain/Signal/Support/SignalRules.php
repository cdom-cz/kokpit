<?php

declare(strict_types=1);

namespace App\Domain\Signal\Support;

use App\Domain\Signal\Enums\SignalCategory;

/**
 * The pure business rules of the planner. They hold no state and touch no database, so the Actions
 * (the authority) and the views (disabling a control) apply the very same rule.
 */
final class SignalRules
{
    public const int MAX_GOALS_PER_WEEK = 3;

    /**
     * A task is added "during the day" when it is created on or after the day it is for; it was
     * "planned ahead" when it is created before it.
     */
    public static function isExtra(string $forDate, string $createdOn): bool
    {
        return $createdOn >= $forDate;
    }

    /**
     * The category to store for a new task: always `extra` when added during the day, else the
     * chosen plannable one. Null when planned ahead without a valid choice.
     */
    public static function resolveNewTaskCategory(string $forDate, ?SignalCategory $chosen, string $createdOn): ?SignalCategory
    {
        if (self::isExtra($forDate, $createdOn)) {
            return SignalCategory::Extra;
        }

        return $chosen !== null && $chosen->isPlannable() ? $chosen : null;
    }

    /**
     * The limit of a category on one day after every active recurring template of that category
     * that fires on the day has reserved a slot; floored at zero. Null when unlimited.
     */
    public static function effectiveLimit(SignalCategory $category, int $recurringCount): ?int
    {
        $limit = $category->dailyLimit();

        return $limit === null ? null : max(0, $limit - $recurringCount);
    }

    public static function canAdd(SignalCategory $category, int $currentCount, int $recurringCount = 0): bool
    {
        $limit = self::effectiveLimit($category, $recurringCount);

        return $limit === null || $currentCount < $limit;
    }

    /**
     * A past day is locked unless the user unlocked it; today and the future never are.
     */
    public static function isDayLocked(string $forDate, bool $unlocked, string $today): bool
    {
        return $forDate < $today && ! $unlocked;
    }

    /**
     * Full editing (add, rename, recolour, delete, reorder) is allowed on a day that is not locked.
     */
    public static function canEditDay(string $forDate, bool $unlocked, string $today): bool
    {
        return ! self::isDayLocked($forDate, $unlocked, $today);
    }

    /**
     * Ticking a task done is allowed today and on past days, even locked ones, never in the future.
     */
    public static function canToggleDone(string $forDate, string $today): bool
    {
        return $forDate <= $today;
    }

    /**
     * Whether a recurring template with these weekdays (0 = Monday ... 6 = Sunday) fires on the day.
     *
     * @param  list<int>  $weekdays
     */
    public static function firesOn(array $weekdays, string $day): bool
    {
        return in_array(SignalCalendar::weekdayIndex($day), $weekdays, true);
    }

    /**
     * The planned deep-work blocks of a day from the settings: weekday or weekend count.
     */
    public static function plannedBlocks(string $day, int $weekdayBlocks, int $weekendBlocks): int
    {
        return SignalCalendar::isWeekend($day) ? $weekendBlocks : $weekdayBlocks;
    }

    public static function clampCompleted(int $completed, int $planned): int
    {
        return max(0, min($completed, $planned));
    }
}

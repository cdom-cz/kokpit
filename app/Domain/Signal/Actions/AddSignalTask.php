<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use App\Domain\Signal\Support\SignalRules;
use Illuminate\Support\Facades\DB;

/**
 * Adds a task to a day of the planner.
 *
 * A task added on or after the day it is for is always grey (`extra`) and unlimited. A task planned
 * ahead needs a plannable colour, and the colour must fit the day's limit after the active recurring
 * templates of that colour reserved their slots. The count and the insert run under a lock of the
 * user's day, so two parallel requests cannot both take the last slot. A locked past day refuses.
 */
final class AddSignalTask
{
    use AuthorizesSignal;

    public function __construct(private readonly SignalDayReader $reader) {}

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $title, string $forDate, ?SignalCategory $chosen): SignalTask
    {
        $this->authorizeActor($actor);

        $title = $this->cleanTitle($title);

        if (! SignalCalendar::isValidDay($forDate)) {
            throw SignalRuleViolation::because('invalid_date');
        }

        return DB::transaction(function () use ($actor, $title, $forDate, $chosen): SignalTask {
            SignalLock::take((string) $actor->getKey(), $forDate);

            $today = SignalCalendar::today();

            if (! SignalRules::canEditDay($forDate, $this->reader->isUnlocked($forDate), $today)) {
                throw SignalRuleViolation::because('day_locked');
            }

            $category = SignalRules::resolveNewTaskCategory($forDate, $chosen, $today)
                ?? throw SignalRuleViolation::because('category_required');

            // Tasks added during the day are grey and unlimited; only planned ones count against the limit.
            if ($category !== SignalCategory::Extra) {
                $this->assertFits($category, $forDate);
            }

            $position = SignalTask::query()->where('for_date', $forDate)->where('category', $category->value)->max('position');

            return SignalTask::query()->create([
                'title' => $title,
                'for_date' => $forDate,
                'category' => $category,
                'position' => $position === null ? 0 : ((int) $position) + 1,
            ]);
        });
    }

    private function assertFits(SignalCategory $category, string $forDate): void
    {
        $counts = $this->reader->categoryCounts($forDate);
        $reserved = $this->reader->recurringCounts($forDate);

        if (! SignalRules::canAdd($category, $counts[$category->value], $reserved[$category->value])) {
            throw SignalRuleViolation::because('limit_'.$category->value, ['limit' => SignalRules::effectiveLimit($category, $reserved[$category->value])]);
        }
    }
}

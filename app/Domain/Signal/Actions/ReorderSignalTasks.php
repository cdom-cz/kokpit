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
 * Stores a drag-and-drop order inside one (day, colour) group. The ids must be exactly the set of
 * tasks the group holds now: a stale list (a task added or deleted meanwhile, or a foreign id) is
 * refused as a whole, so the screen falls back to the stored order instead of half applying.
 */
final class ReorderSignalTasks
{
    use AuthorizesSignal;

    public function __construct(private readonly SignalDayReader $reader) {}

    /**
     * @param  list<string>  $orderedIds
     *
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $forDate, SignalCategory $category, array $orderedIds): void
    {
        $this->authorizeActor($actor);

        if (! SignalCalendar::isValidDay($forDate)) {
            throw SignalRuleViolation::because('invalid_date');
        }

        if ($orderedIds === []) {
            return;
        }

        DB::transaction(function () use ($actor, $forDate, $category, $orderedIds): void {
            SignalLock::take((string) $actor->getKey(), $forDate);

            if (! SignalRules::canEditDay($forDate, $this->reader->isUnlocked($forDate), SignalCalendar::today())) {
                throw SignalRuleViolation::because('day_locked');
            }

            $existing = SignalTask::query()->where('for_date', $forDate)->where('category', $category->value)->lockForUpdate()->pluck('id')->map(strval(...))->all();

            $given = array_values($orderedIds);

            if (count($given) !== count($existing) || array_diff($given, $existing) !== [] || count(array_unique($given)) !== count($given)) {
                throw SignalRuleViolation::because('order_stale');
            }

            foreach ($given as $index => $id) {
                SignalTask::query()->whereKey($id)->update(['position' => $index]);
            }
        });
    }
}

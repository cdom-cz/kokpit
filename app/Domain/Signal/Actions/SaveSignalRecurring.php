<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalRecurringTask;

/**
 * Creates a recurring template, or updates the one with the given id (title, colour, weekdays).
 * The colour must be plannable and at least one weekday (0 = Monday ... 6 = Sunday) is needed.
 * Tasks already generated from the template are not touched.
 */
final class SaveSignalRecurring
{
    use AuthorizesSignal;

    /**
     * @param  list<int>  $weekdays
     *
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, ?string $id, string $title, ?SignalCategory $category, array $weekdays): SignalRecurringTask
    {
        $this->authorizeActor($actor);

        $title = $this->cleanTitle($title);

        if ($category === null || ! $category->isPlannable()) {
            throw SignalRuleViolation::because('category_required');
        }

        // Anything that is no weekday index is dropped; nothing left means no day was chosen.
        $weekdays = array_values(array_unique(array_filter($weekdays, static fn (int $weekday): bool => $weekday >= 0 && $weekday <= 6)));

        if ($weekdays === []) {
            throw SignalRuleViolation::because('weekdays_required');
        }

        $template = $id === null ? new SignalRecurringTask : $this->findOwned(SignalRecurringTask::class, $id);
        $template->title = $title;
        $template->category = $category;
        $template->weekdays = $weekdays;
        $template->save();

        return $template;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Models\SignalWeeklyGoal;

/**
 * Renames a goal.
 */
final class UpdateSignalGoal
{
    use AuthorizesSignal;

    public function handle(User $actor, string $id, string $title): SignalWeeklyGoal
    {
        $this->authorizeActor($actor);

        $title = $this->cleanTitle($title);

        $goal = $this->findOwned(SignalWeeklyGoal::class, $id);
        $goal->title = $title;
        $goal->save();

        return $goal;
    }
}

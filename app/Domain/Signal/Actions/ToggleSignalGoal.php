<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Models\SignalWeeklyGoal;

/**
 * Ticks a goal done or undone.
 */
final class ToggleSignalGoal
{
    use AuthorizesSignal;

    public function handle(User $actor, string $id, bool $done): SignalWeeklyGoal
    {
        $this->authorizeActor($actor);

        $goal = $this->findOwned(SignalWeeklyGoal::class, $id);
        $goal->is_done = $done;
        $goal->save();

        return $goal;
    }
}

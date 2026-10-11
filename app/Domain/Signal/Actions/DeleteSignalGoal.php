<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Models\SignalWeeklyGoal;

/**
 * Deletes a goal. The other goals of the week keep their positions; the next goal fills the gap.
 */
final class DeleteSignalGoal
{
    use AuthorizesSignal;

    public function handle(User $actor, string $id): void
    {
        $this->authorizeActor($actor);

        $this->findOwned(SignalWeeklyGoal::class, $id)->delete();
    }
}

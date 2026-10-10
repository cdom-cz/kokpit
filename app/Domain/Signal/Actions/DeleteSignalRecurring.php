<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Models\SignalRecurringTask;

/**
 * Deletes a recurring template. The tasks already generated from it stay (the database nulls their
 * `recurring_id`), so no day is disturbed.
 */
final class DeleteSignalRecurring
{
    use AuthorizesSignal;

    public function handle(User $actor, string $id): void
    {
        $this->authorizeActor($actor);

        $this->findOwned(SignalRecurringTask::class, $id)->delete();
    }
}

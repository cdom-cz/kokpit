<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Models\SignalRecurringTask;

/**
 * Switches a recurring template on or off without deleting it.
 */
final class ToggleSignalRecurring
{
    use AuthorizesSignal;

    public function handle(User $actor, string $id, bool $active): SignalRecurringTask
    {
        $this->authorizeActor($actor);

        $template = $this->findOwned(SignalRecurringTask::class, $id);
        $template->active = $active;
        $template->save();

        return $template;
    }
}

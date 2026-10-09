<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Stores whether the Admin keeps the side panel "Poslední záznamy" open at docked width (D-08).
 *
 * The choice is personal: it is written to the actor's own row only, never to a row the caller
 * names, and only the Admin has the panel at all. The column is not mass-assignable, so this
 * Action is its only writer.
 */
final class SetTimePanelOpen
{
    /**
     * @throws AuthorizationException when the actor is not the Admin
     */
    public function handle(User $actor, bool $open): void
    {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);

        $actor->forceFill(['time_panel_open' => $open])->save();
    }
}

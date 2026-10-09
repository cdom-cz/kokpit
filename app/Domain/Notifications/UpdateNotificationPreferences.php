<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use stdClass;

/**
 * Stores the notification switches of a user (D-15).
 *
 * The column is not mass-assignable, so this Action is the only writer. Switches are
 * personal: only the owner may change them, the Admin included.
 */
final class UpdateNotificationPreferences
{
    /**
     * @param  array<array-key, mixed>  $input  event => channel => on
     *
     * @throws AuthorizationException when the target is not the actor
     */
    public function handle(User $actor, User $target, array $input): void
    {
        if ($actor->id !== $target->id) {
            throw new AuthorizationException;
        }

        $stored = NotificationPreferences::fromInput($input)->toArray();

        // An empty PHP array would be encoded as the JSON array [], which the column's
        // object check refuses; an empty object is the empty set of switches.
        $target->forceFill(['notification_preferences' => $stored === [] ? new stdClass : $stored])->save();
    }
}

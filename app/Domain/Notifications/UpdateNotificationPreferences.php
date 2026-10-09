<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use stdClass;

/**
 * Stores the notification switches of a user (D-15).
 *
 * The column is not mass-assignable, so this Action is the only writer.
 */
final class UpdateNotificationPreferences
{
    /**
     * @param  array<array-key, mixed>  $input  event => channel => on
     */
    public function handle(User $actor, User $target, array $input): void
    {
        $stored = NotificationPreferences::fromInput($input)->toArray();

        // An empty PHP array would be encoded as the JSON array [], which the column's
        // object check refuses; an empty object is the empty set of switches.
        $target->forceFill(['notification_preferences' => $stored === [] ? new stdClass : $stored])->save();
    }
}

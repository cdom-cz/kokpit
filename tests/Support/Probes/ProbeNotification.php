<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use Illuminate\Notifications\Notification;

/**
 * A database notification with a fictional payload, used to prove that the
 * notifications table follows the data conventions.
 */
final class ProbeNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['message' => 'Fictional probe notification'];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a notification can arrive (D-15): by e-mail or in the Filament bell.
 *
 * The values are the keys of users.notification_preferences.
 */
enum NotificationChannel: string implements HasLabel
{
    case Mail = 'mail';
    case Database = 'database';

    /**
     * The Laravel notification channel name that carries this channel.
     */
    public function laravelChannel(): string
    {
        return match ($this) {
            self::Mail => 'mail',
            self::Database => 'database',
        };
    }

    public function getLabel(): string
    {
        return __('enums.notification_channel.'.$this->value);
    }
}

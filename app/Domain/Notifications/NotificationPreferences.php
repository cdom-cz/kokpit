<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;

/**
 * What one user has switched off (D-15).
 *
 * Preferences only narrow delivery: a missing key means the channel is on, and
 * nothing stored here can create a delivery the system forbids. The rule that an
 * internal comment never reaches a Partner is enforced before any preference is
 * read (plan 05-15).
 */
final readonly class NotificationPreferences
{
    /**
     * @param  array<string, array<string, bool>>  $values  event value => channel value => on
     */
    private function __construct(private array $values) {}

    public static function for(User $user): self
    {
        $stored = $user->notification_preferences;

        return is_array($stored) ? self::fromInput($stored) : new self([]);
    }

    /**
     * Keeps only the known events and channels.
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $values = [];

        foreach (NotificationEvent::cases() as $event) {
            $row = $input[$event->value] ?? null;

            if (! is_array($row)) {
                continue;
            }

            foreach (NotificationChannel::cases() as $channel) {
                if (array_key_exists($channel->value, $row)) {
                    $values[$event->value][$channel->value] = (bool) $row[$channel->value];
                }
            }
        }

        return new self($values);
    }

    public function allows(NotificationEvent $event, NotificationChannel $channel): bool
    {
        return $this->values[$event->value][$channel->value] ?? true;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}

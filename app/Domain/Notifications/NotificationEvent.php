<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\RoleName;
use Filament\Support\Contracts\HasLabel;

/**
 * The kinds of event a user can switch on or off per channel (D-15).
 *
 * The values are the keys of users.notification_preferences. Later phases (exports,
 * invoices, payments) add cases here. `forRole` lists what a role can receive at all:
 * the profile page shows exactly those rows, so a switch never exists for a
 * delivery the system will not make.
 */
enum NotificationEvent: string implements HasLabel
{
    case TaskCreated = 'task_created';
    case Comment = 'comment';
    case Escalation = 'escalation';
    case AssignmentChange = 'assignment_change';

    /**
     * The events a role can receive. A Partner is told of an escalation because a
     * Partner can be the assignee of the task (D-07); the Admin is told when a
     * Partner creates a task, comments or escalates.
     *
     * @return list<self>
     */
    public static function forRole(RoleName $role): array
    {
        return match ($role) {
            RoleName::Admin => [self::TaskCreated, self::Comment, self::Escalation],
            RoleName::Partner => [self::Comment, self::Escalation, self::AssignmentChange],
        };
    }

    public function getLabel(): string
    {
        return __('enums.notification_event.'.$this->value);
    }

    /**
     * The one-line explanation shown under the label on the profile page.
     */
    public function helper(): string
    {
        return __('kokpit.notifications.profile.helpers.'.$this->value);
    }
}

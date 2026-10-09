<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Notifications\NotificationEvent;
use LogicException;

/**
 * Tells the assignee of a task, or the Admin as the fallback, that it was
 * escalated (TA-07, D-06, D-07).
 *
 * The excerpt is the plain text of the escalation comment, which is never
 * internal. The internal guard of the base stays in force all the same: an
 * internal comment cannot be built for a Partner and carries no excerpt. It holds
 * raw values and no text; the base class names the texts and escapes every value.
 */
final class TaskEscalatedNotification extends TaskNotification
{
    /**
     * @throws LogicException when an internal comment is addressed to a Partner
     */
    public function __construct(
        string $taskReference,
        string $taskTitle,
        string $projectKey,
        string $actorName,
        ?string $excerpt,
        string $url,
        bool $recipientIsPartner,
        bool $internal = false,
    ) {
        parent::__construct(
            event: NotificationEvent::Escalation,
            taskReference: $taskReference,
            taskTitle: $taskTitle,
            projectKey: $projectKey,
            actorName: $actorName,
            excerpt: $internal ? null : $excerpt,
            url: $url,
            recipientIsPartner: $recipientIsPartner,
            internal: $internal,
        );
    }

    protected function textGroup(): string
    {
        return 'escalated';
    }
}

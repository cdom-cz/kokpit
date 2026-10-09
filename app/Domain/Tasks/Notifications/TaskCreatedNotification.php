<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Notifications\NotificationEvent;

/**
 * Tells the Admin that a Partner created a task (TA-07, D-07).
 *
 * It never carries an excerpt: the task description is not part of the
 * notification, only the reference, the title and the project key. It holds raw
 * values and no text; the base class names the texts and escapes every value.
 */
final class TaskCreatedNotification extends TaskNotification
{
    public function __construct(
        string $taskReference,
        string $taskTitle,
        string $projectKey,
        string $actorName,
        string $url,
    ) {
        parent::__construct(
            event: NotificationEvent::TaskCreated,
            taskReference: $taskReference,
            taskTitle: $taskTitle,
            projectKey: $projectKey,
            actorName: $actorName,
            excerpt: null,
            url: $url,
            recipientIsPartner: false,
        );
    }

    protected function textGroup(): string
    {
        return 'task_created';
    }

    protected function bellBodyKey(): string
    {
        return 'bell_body';
    }
}

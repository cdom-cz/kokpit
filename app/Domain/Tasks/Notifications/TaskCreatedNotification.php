<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Notifications\NotificationEvent;

/**
 * Tells the Admin that a Partner created a task (TA-07, D-07).
 *
 * It never carries an excerpt: the task description is not part of the
 * notification, only the reference, the title and the project key.
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

    protected function mailSubject(): string
    {
        return __('kokpit.tasks.notifications.task_created.mail_subject', [
            'reference' => $this->taskReference,
            'title' => $this->taskTitle,
        ]);
    }

    protected function mailLine(): string
    {
        return __('kokpit.tasks.notifications.task_created.mail_line', [
            'project' => $this->projectKey,
            'actor' => $this->actorName,
        ]);
    }

    protected function bellTitle(): string
    {
        return __('kokpit.tasks.notifications.task_created.bell_title', ['reference' => $this->taskReference]);
    }

    protected function bellBody(): string
    {
        return __('kokpit.tasks.notifications.task_created.bell_body', [
            'title' => $this->taskTitle,
            'project' => $this->projectKey,
        ]);
    }
}

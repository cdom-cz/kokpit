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
 * internal comment cannot be built for a Partner and carries no excerpt.
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

    protected function mailSubject(): string
    {
        return __('kokpit.tasks.notifications.escalated.mail_subject', [
            'reference' => $this->taskReference,
            'title' => $this->taskTitle,
        ]);
    }

    protected function mailLine(): string
    {
        return __('kokpit.tasks.notifications.escalated.mail_line', [
            'reference' => $this->taskReference,
            'title' => $this->taskTitle,
            'actor' => $this->actorName,
        ]);
    }

    protected function bellTitle(): string
    {
        return __('kokpit.tasks.notifications.escalated.bell_title', ['reference' => $this->taskReference]);
    }

    protected function bellBody(): string
    {
        $excerpt = $this->bellExcerpt();

        if ($excerpt === null) {
            return __('kokpit.tasks.notifications.escalated.bell_body_no_excerpt', ['actor' => $this->actorName]);
        }

        return __('kokpit.tasks.notifications.escalated.bell_body', [
            'actor' => $this->actorName,
            'excerpt' => $excerpt,
        ]);
    }
}

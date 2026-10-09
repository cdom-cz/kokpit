<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Notifications\NotificationEvent;
use LogicException;

/**
 * Tells the other side of a task that a comment was added (TA-07, D-07).
 *
 * The excerpt is the plain text of the already sanitised, non-internal body. An
 * internal comment carries no excerpt at all, and it cannot be built for a
 * Partner: the constructor of the base refuses it with a LogicException before
 * any preference is read.
 */
final class TaskCommentedNotification extends TaskNotification
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
            event: NotificationEvent::Comment,
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
        return __('kokpit.tasks.notifications.comment.mail_subject', ['reference' => $this->taskReference]);
    }

    protected function mailLine(): string
    {
        return __('kokpit.tasks.notifications.comment.mail_line', [
            'reference' => $this->taskReference,
            'title' => $this->taskTitle,
            'actor' => $this->actorName,
        ]);
    }

    protected function bellTitle(): string
    {
        return __('kokpit.tasks.notifications.comment.bell_title', ['reference' => $this->taskReference]);
    }

    protected function bellBody(): string
    {
        $excerpt = $this->bellExcerpt();

        if ($excerpt === null) {
            return __('kokpit.tasks.notifications.comment.bell_body_no_excerpt', ['actor' => $this->actorName]);
        }

        return __('kokpit.tasks.notifications.comment.bell_body', [
            'actor' => $this->actorName,
            'excerpt' => $excerpt,
        ]);
    }
}

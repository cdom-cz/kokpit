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
 * any preference is read. It holds raw values and no text; the base class names
 * the texts and escapes every value.
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

    protected function textGroup(): string
    {
        return 'comment';
    }
}

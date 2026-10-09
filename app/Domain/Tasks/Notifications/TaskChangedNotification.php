<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Notifications\NotificationEvent;

/**
 * Tells a task's people that it changed (TA-07, D-07, D-15 "assignment or change").
 *
 * It is for a Partner when the Admin changed the status, priority or assignee of a
 * task of theirs, and for the Admin and the assignee when a Partner changed the
 * description (`$recipientIsPartner` names the audience, which decides the link).
 * It never carries an excerpt. The changed fields arrive as ready Czech lines
 * ("Stav: old to new", "Popis upraven uživatelem ..."), built by TaskNotifier from
 * the labels of the enums and the names of the people, so no other data of the
 * task, and never a description text, can be in the message. It holds raw values
 * and no text; the base class names the texts and escapes every value.
 */
final class TaskChangedNotification extends TaskNotification
{
    /**
     * @param  list<string>  $changedLabels
     */
    public function __construct(
        string $taskReference,
        string $taskTitle,
        string $projectKey,
        string $actorName,
        string $url,
        public readonly array $changedLabels,
        bool $recipientIsPartner = true,
    ) {
        parent::__construct(
            event: NotificationEvent::AssignmentChange,
            taskReference: $taskReference,
            taskTitle: $taskTitle,
            projectKey: $projectKey,
            actorName: $actorName,
            excerpt: null,
            url: $url,
            recipientIsPartner: $recipientIsPartner,
        );
    }

    protected function textGroup(): string
    {
        return 'changed';
    }

    /**
     * One raw line per change; the base class escapes each for the mail and the bell.
     *
     * @return list<string>
     */
    protected function changeLines(): array
    {
        return $this->changedLabels;
    }
}

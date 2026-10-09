<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Notifications\NotificationEvent;

/**
 * Tells a Partner that the Admin changed the status, priority or assignee of a
 * task of theirs (TA-07, D-07, D-15 "assignment or change").
 *
 * It is always for a Partner and never carries an excerpt. The changed fields
 * arrive as ready Czech lines ("Stav: old to new"), built by TaskNotifier from the
 * labels of the enums and the names of the two people, so no other data of the
 * task can be in the message.
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
    ) {
        parent::__construct(
            event: NotificationEvent::AssignmentChange,
            taskReference: $taskReference,
            taskTitle: $taskTitle,
            projectKey: $projectKey,
            actorName: $actorName,
            excerpt: null,
            url: $url,
            recipientIsPartner: true,
        );
    }

    protected function mailSubject(): string
    {
        return __('kokpit.tasks.notifications.changed.mail_subject', [
            'reference' => $this->taskReference,
            'title' => $this->taskTitle,
        ]);
    }

    protected function mailLine(): string
    {
        return __('kokpit.tasks.notifications.changed.mail_line', [
            'reference' => $this->taskReference,
            'title' => $this->taskTitle,
        ]);
    }

    /**
     * The event line followed by one line per change.
     *
     * @return list<string>
     */
    protected function mailLines(): array
    {
        return [
            $this->mailLine(),
            ...array_map(static fn (string $label): string => self::escapeMarkdown($label), $this->changedLabels),
        ];
    }

    protected function bellTitle(): string
    {
        return __('kokpit.tasks.notifications.changed.bell_title', ['reference' => $this->taskReference]);
    }

    /**
     * One line per change; the bell renders sanitised HTML, so each line is
     * escaped and the lines are joined with a line break.
     */
    protected function bellBody(): string
    {
        return implode('<br>', array_map(static fn (string $label): string => e($label), $this->changedLabels));
    }
}

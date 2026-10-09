<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationPreferences;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use LogicException;

/**
 * The base of every task notification: one e-mail and one bell entry, queued,
 * filtered by the recipient's preferences (TA-07, D-07, D-15).
 *
 * The constructor takes scalars only (the reference, title, project key, author
 * name, a plain-text excerpt and the URL of the recipient's audience), all
 * prepared by TaskNotifier at dispatch time. A queued notification runs in a
 * worker with no signed-in user and no Partner context, so nothing here may
 * reload a task or a comment, and nothing here does.
 *
 * An internal comment never reaches a Partner. The notifier already returns no
 * Partner for it; this is the second lock, and it sits in the constructor so it
 * holds before any preference is read and for any code that builds the
 * notification by hand. An internal comment carries no excerpt either.
 *
 * Preferences only narrow: `via()` keeps the channels the recipient has not
 * switched off for the event, and a recipient who switched both off gets nothing.
 */
abstract class TaskNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const int EXCERPT_BELL_LIMIT = 120;

    /**
     * @throws LogicException when an internal comment is addressed to a Partner
     */
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly string $taskReference,
        public readonly string $taskTitle,
        public readonly string $projectKey,
        public readonly string $actorName,
        public readonly ?string $excerpt,
        public readonly string $url,
        public readonly bool $recipientIsPartner,
        public readonly bool $internal = false,
    ) {
        if ($internal && $recipientIsPartner) {
            throw new LogicException('An internal comment must never be notified to a Partner.');
        }

        $this->afterCommit();
    }

    /**
     * The Laravel channels the recipient allows for this event.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $preferences = NotificationPreferences::for($notifiable);
        $channels = [];

        foreach (NotificationChannel::cases() as $channel) {
            if ($preferences->allows($this->event, $channel)) {
                $channels[] = $channel->laravelChannel();
            }
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->mailSubject())
            ->greeting(__('kokpit.tasks.notifications.shared.greeting'))
            ->line($this->mailLine());

        if ($this->excerpt !== null && $this->excerpt !== '') {
            $message->line('> '.self::escapeMarkdown($this->excerpt));
        }

        return $message
            ->action(__('kokpit.tasks.notifications.shared.action'), $this->url)
            ->line(__('kokpit.tasks.notifications.shared.footer'));
    }

    /**
     * The bell entry in the Filament database format, with one button to the task
     * page of the recipient's audience.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->bellTitle())
            ->body($this->bellBody())
            ->actions([
                Action::make('open')
                    ->label(__('kokpit.tasks.notifications.shared.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }

    abstract protected function mailSubject(): string;

    abstract protected function mailLine(): string;

    abstract protected function bellTitle(): string;

    abstract protected function bellBody(): string;

    /**
     * The excerpt cut for the narrow bell entry.
     */
    protected function bellExcerpt(): ?string
    {
        if ($this->excerpt === null || $this->excerpt === '') {
            return null;
        }

        return Str::limit($this->excerpt, self::EXCERPT_BELL_LIMIT - 1, '…');
    }

    /**
     * Makes a plain-text excerpt inert in the Markdown of the mail: no link,
     * emphasis, code or heading can be built from the words of a comment.
     */
    private static function escapeMarkdown(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]()<>#|~!])/', '\\\\$1', $text);
    }
}

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

    private const string TARGET_SUBJECT = 'subject';

    private const string TARGET_MAIL = 'mail';

    private const string TARGET_BELL = 'bell';

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

    /**
     * The mail: the subject is plain text (a header is never rendered as markup),
     * every value in the body lines is Markdown-escaped.
     */
    final public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->text('mail_subject', self::TARGET_SUBJECT))
            ->greeting(__('kokpit.tasks.notifications.shared.greeting'))
            ->line($this->text('mail_line', self::TARGET_MAIL));

        foreach ($this->changeLines() as $line) {
            $message->line(self::escapeMarkdown($line));
        }

        if ($this->excerpt !== null && $this->excerpt !== '') {
            $message->line('> '.self::escapeMarkdown($this->excerpt));
        }

        return $message
            ->action(__('kokpit.tasks.notifications.shared.action'), $this->url)
            ->line(__('kokpit.tasks.notifications.shared.footer'));
    }

    /**
     * The bell entry in the Filament database format, with one button to the task
     * page of the recipient's audience. Filament renders the title and the body as
     * sanitised HTML, so every value is HTML-escaped here and reads as written.
     *
     * @return array<string, mixed>
     */
    final public function toDatabase(object $notifiable): array
    {
        $changes = $this->changeLines();

        $body = $changes !== []
            ? implode('<br>', array_map(static fn (string $line): string => e($line), $changes))
            : $this->text($this->bellBodyKey(), self::TARGET_BELL);

        return FilamentNotification::make()
            ->title($this->text('bell_title', self::TARGET_BELL))
            ->body($body)
            ->actions([
                Action::make('open')
                    ->label(__('kokpit.tasks.notifications.shared.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }

    /**
     * The segment under `kokpit.tasks.notifications.` that holds the texts of the
     * event: `task_created`, `comment`, `escalated` or `changed`.
     */
    abstract protected function textGroup(): string;

    /**
     * The raw lines that describe the changes of the event, one per line; none by
     * default. They are escaped per channel by this class, never by the subclass.
     *
     * @return list<string>
     */
    protected function changeLines(): array
    {
        return [];
    }

    /**
     * The translation key of the bell body when the event has no change lines:
     * the one with the excerpt when there is one, otherwise the one without.
     */
    protected function bellBodyKey(): string
    {
        return $this->bellExcerpt() === null ? 'bell_body_no_excerpt' : 'bell_body';
    }

    /**
     * The one place that reads a text of the event and puts the values into it.
     * A value is escaped for the target and for nothing else, once, here, from the
     * raw scalar the notification holds, so a second render gives the same output.
     */
    private function text(string $key, string $target): string
    {
        $values = [
            'reference' => $this->taskReference,
            'title' => $this->taskTitle,
            'project' => $this->projectKey,
            'actor' => $this->actorName,
            'excerpt' => $this->bellExcerpt() ?? '',
        ];

        $escaped = match ($target) {
            self::TARGET_MAIL => array_map(self::escapeMarkdown(...), $values),
            self::TARGET_BELL => array_map(static fn (string $value): string => e($value), $values),
            default => $values,
        };

        return __('kokpit.tasks.notifications.'.$this->textGroup().'.'.$key, $escaped);
    }

    /**
     * The excerpt cut for the narrow bell entry. It is cut as plain text, before
     * any escaping, so no entity or escape sequence is cut in half.
     */
    private function bellExcerpt(): ?string
    {
        if ($this->excerpt === null || $this->excerpt === '') {
            return null;
        }

        return Str::limit($this->excerpt, self::EXCERPT_BELL_LIMIT - 1, '…');
    }

    /**
     * Makes a plain-text value inert in the Markdown of the mail: no link, image,
     * emphasis, code, heading or table can be built from it, and a line break can
     * not start a new block.
     *
     * `<` and `>` are left alone on purpose: the mail renderer HTML-encodes every
     * line before it parses the Markdown, so they can not open a tag, and a
     * backslash in front of them would make the renderer show a literal "&lt;".
     * Do not apply e() to a mail line either: it would be encoded twice and read
     * "&amp;".
     */
    private static function escapeMarkdown(string $text): string
    {
        $flat = (string) preg_replace('/\s+/u', ' ', $text);

        return (string) preg_replace('/([\\\\`*_\[\]()#|~!])/', '\\\\$1', $flat);
    }
}

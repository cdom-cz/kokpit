<?php

declare(strict_types=1);

namespace App\Domain\Operations\Alerts;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An alert to the Admin by e-mail and in the Filament bell (D-11).
 *
 * Deliberately not queueable: it implements no queue interface and uses no
 * queue trait, so a notification sent with sendNow() never depends on the
 * queue that may be the very thing that is broken. Filament's own
 * sendToDatabase() would queue a queueable notification, hence this class with
 * a Filament-format toDatabase().
 *
 * The body is plain text, one line per row, already shortened and stripped of
 * payloads by the code that builds it.
 */
final class OperationalAlert extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl = null,
    ) {}

    /**
     * The same alert with a line appended to the body.
     */
    public function withLine(string $line): self
    {
        return new self($this->title, $this->body."\n".$line, $this->actionUrl);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->error()
            ->subject($this->title)
            ->greeting($this->title);

        foreach (explode("\n", $this->body) as $line) {
            $message->line($line);
        }

        if ($this->actionUrl !== null) {
            $message->action(__('kokpit.alerts.link_label'), $this->actionUrl);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->danger();

        if ($this->actionUrl !== null) {
            $notification->actions([
                Action::make('open_system')
                    ->label(__('kokpit.alerts.link_label'))
                    ->url($this->actionUrl),
            ]);
        }

        return $notification->getDatabaseMessage();
    }
}

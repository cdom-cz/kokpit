<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Notifications;

use App\Domain\TimeTracking\Support\DurationFormat;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for a timer that was left running past the threshold (TI-09, D-07).
 *
 * Deliberately not queueable: the job sends it with notifyNow() inside the transaction
 * that claims the entry, so the claim and the notice commit or roll back together. A
 * queued notification could be lost after the claim and the Admin would never hear of
 * the timer. Same reasoning as OperationalAlert.
 *
 * The constructor takes scalars only, prepared by the job at dispatch time, so nothing
 * here reloads an entry or a client. The body carries the client name and the duration
 * and nothing else: no description, no task title, no amount. Filament renders the
 * title and the body as sanitised HTML, so the client name is HTML-escaped exactly once,
 * here, from the raw scalar; the duration is digits and a colon.
 *
 * Database channel only: the profile page has no switch for it and the e-mail channel
 * is a deferred idea.
 */
final class LongRunningTimerNotification extends Notification
{
    public function __construct(
        public readonly string $clientName,
        public readonly int $elapsedSeconds,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('kokpit.time.long_running.bell_title'))
            ->body(__('kokpit.time.long_running.bell_body', [
                'client' => e($this->clientName),
                'duration' => DurationFormat::hoursMinutes($this->elapsedSeconds),
            ]))
            ->danger()
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->actions([
                Action::make('open')
                    ->label(__('kokpit.time.long_running.bell_action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reports\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for a finished report export (RE-03, D-09; UI-SPEC Surface H).
 *
 * Deliberately not queueable: the export job sends it with notifyNow() in the transaction that marks the record
 * ready, so the notice and the state commit or roll back together (pattern of LongRunningTimerNotification).
 *
 * The constructor takes scalars prepared by the job. The body names the format and the period and the date the
 * file is kept until, and nothing else: no client name, no amount, no path. Filament renders the body as
 * sanitised HTML, so every value is HTML-escaped exactly once, here, from the raw scalar.
 *
 * Database channel only: the profile page has no switch for it and e-mail is not part of this notice.
 */
final class ReportExportReadyNotification extends Notification
{
    /**
     * @param  string  $format  the format label, "CSV"
     * @param  string  $period  the formatted `from – to`
     * @param  string  $availableUntil  the formatted day the file is kept until
     * @param  string  $url  the exports list
     */
    public function __construct(
        public readonly string $format,
        public readonly string $period,
        public readonly string $availableUntil,
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
            ->title(__('kokpit.exports.bell.ready.title'))
            ->body(__('kokpit.exports.bell.ready.body', [
                'format' => e($this->format),
                'period' => e($this->period),
                'date' => e($this->availableUntil),
            ]))
            ->success()
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->actions([
                Action::make('open')
                    ->label(__('kokpit.exports.bell.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

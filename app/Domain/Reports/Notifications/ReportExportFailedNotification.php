<?php

declare(strict_types=1);

namespace App\Domain\Reports\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for a report export that could not be built (RE-03, D-09; UI-SPEC Surface H).
 *
 * Not queueable, for the reason of {@see ReportExportReadyNotification}: the job's failure handler marks the
 * record failed and tells the Admin in one step. The body names the period only, never the exception, a client
 * name, an amount or a path; the period is HTML-escaped exactly once, here.
 *
 * Database channel only. The Phase 3 failed-job alert reports the technical side separately.
 */
final class ReportExportFailedNotification extends Notification
{
    /**
     * @param  string  $period  the formatted `from – to`
     * @param  string  $url  the exports list
     */
    public function __construct(
        public readonly string $period,
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
            ->title(__('kokpit.exports.bell.failed.title'))
            ->body(__('kokpit.exports.bell.failed.body', ['period' => e($this->period)]))
            ->danger()
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->actions([
                Action::make('open')
                    ->label(__('kokpit.exports.bell.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

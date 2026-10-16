<?php

declare(strict_types=1);

namespace App\Domain\Documents\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for a ZIP archive that could not be built (DO-02, D-08).
 *
 * Not queueable, for the reason of {@see DocumentArchiveReadyNotification}: the job marks the record failed and
 * tells the Admin in one step. The text is fixed: it never names the exception, a file, a client or a path. The
 * Phase 3 failed-job alert reports the technical side separately.
 *
 * Database channel only.
 */
final class DocumentArchiveFailedNotification extends Notification
{
    /**
     * @param  string  $url  the archives list
     */
    public function __construct(public readonly string $url) {}

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
            ->title(__('documents.archives.bell.failed.title'))
            ->body(__('documents.archives.bell.failed.body'))
            ->danger()
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->actions([
                Action::make('open')
                    ->label(__('documents.archives.bell.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

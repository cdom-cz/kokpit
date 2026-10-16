<?php

declare(strict_types=1);

namespace App\Domain\Documents\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for a finished ZIP archive (DO-02, D-08).
 *
 * Deliberately not queueable: the build job sends it with notifyNow() in the transaction that marks the record
 * ready, so the notice and the state commit or roll back together (pattern of ReportExportReadyNotification).
 *
 * The constructor takes scalars prepared by the job. The body names the number of files and the day the archive
 * is kept until, and nothing else: no file name, no client name, no path. Filament renders the body as sanitised
 * HTML, so every value is HTML-escaped exactly once, here, from the raw scalar.
 *
 * Database channel only: D-08 has no e-mail, and a Partner never receives it (the recipient is the Admin who
 * asked for the archive).
 */
final class DocumentArchiveReadyNotification extends Notification
{
    /**
     * @param  int  $count  the number of files in the ZIP
     * @param  string  $expiresOn  the formatted day the archive is kept until
     * @param  string  $url  the archives list
     */
    public function __construct(
        public readonly int $count,
        public readonly string $expiresOn,
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
            ->title(__('documents.archives.bell.ready.title'))
            ->body(trans_choice('documents.archives.bell.ready.body', $this->count, [
                'count' => $this->count,
                'date' => e($this->expiresOn),
            ]))
            ->success()
            ->icon(Heroicon::OutlinedArchiveBox)
            ->actions([
                Action::make('open')
                    ->label(__('documents.archives.bell.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

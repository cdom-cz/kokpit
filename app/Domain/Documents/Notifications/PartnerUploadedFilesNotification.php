<?php

declare(strict_types=1);

namespace App\Domain\Documents\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry an Admin gets when a Partner hands over files on a task (DO-01, D-05, research A18).
 *
 * One entry per upload submission, however many files it holds. Deliberately not queueable: DocumentNotifier
 * sends it with notifyNow() inside the upload transaction, so a rolled-back upload sends nothing (pattern of
 * ReportExportReadyNotification).
 *
 * The constructor takes scalars. The body names the Partner, the task reference and the number of files, and
 * never a file name: a name is text the Partner chose. Filament renders the body as sanitised HTML, so every
 * value is HTML-escaped exactly once, here, from the raw scalar.
 *
 * Database channel only (Phase 5 D-15: Admin notifications for Partner activity are bell only).
 */
final class PartnerUploadedFilesNotification extends Notification
{
    /**
     * @param  string  $partnerName  the display name of the uploading Partner
     * @param  string  $taskReference  the task reference, `KEY-N`
     * @param  int  $count  the number of files in the submission
     * @param  string  $url  the Admin task page
     */
    public function __construct(
        public readonly string $partnerName,
        public readonly string $taskReference,
        public readonly int $count,
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
            ->title(__('documents.bell.partner_uploaded.title'))
            ->body(trans_choice('documents.bell.partner_uploaded.body', $this->count, [
                'partner' => e($this->partnerName),
                'reference' => e($this->taskReference),
                'count' => $this->count,
            ]))
            ->info()
            ->icon(Heroicon::OutlinedPaperClip)
            ->actions([
                Action::make('open')
                    ->label(__('documents.bell.partner_uploaded.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

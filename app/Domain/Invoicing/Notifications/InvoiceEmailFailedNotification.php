<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for a document e-mail that failed for good (IN-04, D-22; UI-SPEC Surface G).
 *
 * Not queueable, for the reason of the archive notices: the send job marks the row failed and tells the Admins in one
 * step. The text is fixed and names the document number only: never the recipient address, an amount, the exception
 * or a provider answer (T-10-65). The Phase 3 failed-job alert reports the technical side separately.
 *
 * Database channel only.
 */
final class InvoiceEmailFailedNotification extends Notification
{
    /**
     * @param  string  $number  the number of the document
     * @param  string  $url  the detail of the document
     */
    public function __construct(
        public readonly string $number,
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
            ->title(__('invoices.email.notice.title'))
            ->body(__('invoices.email.notice.body', ['number' => $this->number]))
            ->danger()
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->actions([
                Action::make('open')
                    ->label(__('invoices.email.notice.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

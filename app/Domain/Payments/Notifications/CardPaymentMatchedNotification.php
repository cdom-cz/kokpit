<?php

declare(strict_types=1);

namespace App\Domain\Payments\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;

/**
 * The bell entry for a card payment that was matched to its document (PA-06, D-10; UI-SPEC Surface I, matched row).
 *
 * Not queueable, like the other Admin notices: it is sent with `notifyNow` once the match has committed. The text names
 * the document number and the amount only, never a Stripe reference, the payer's name or e-mail (T-11-48). A proforma
 * has its own wording. Database channel only.
 */
final class CardPaymentMatchedNotification extends Notification
{
    /**
     * @param  string  $number  the number of the document
     * @param  string  $amount  the paid amount, formatted for display
     * @param  bool  $proforma  whether the document is a proforma
     * @param  string  $url  the detail of the document
     */
    public function __construct(
        public readonly string $number,
        public readonly string $amount,
        public readonly bool $proforma,
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
            ->title(__('payment_notices.matched.title'))
            ->body(__($this->proforma ? 'payment_notices.matched.body_proforma' : 'payment_notices.matched.body', [
                'number' => $this->number,
                'amount' => $this->amount,
            ]))
            ->success()
            ->icon(Heroicon::OutlinedCheckCircle)
            ->actions([
                Action::make('open')
                    ->label(__('payment_notices.matched.action'))
                    ->url($this->url),
            ])
            ->getDatabaseMessage();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Operations\Alerts\AdminAlerter;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Notifications\CardPaymentMatchedNotification;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\CardPaymentResource;

/**
 * The three problem notices of the card payments to every Admin, bell and e-mail (PA-07, PA-08; D-11, D-12, D-14;
 * UI-SPEC Surface I; R11-16-1).
 *
 * The throttle key is the record, so two duplicates within minutes are two notices and no money is merged into an
 * earlier one (T-11-55). A notice holds the document number, the amount and the label of a fixed reason, and the
 * action opens the record: never a Stripe reference, a payer or provider text (T-11-56), so its length is bounded.
 * The alerter is synchronous and never throws; the callers send after the commit.
 */
final readonly class CardPaymentAlerts
{
    public function __construct(private AdminAlerter $alerter) {}

    /**
     * The notice that fits the record: a duplicate, an unmatched payment or a refund; a matched record has none here
     * (its bell notice is {@see CardPaymentMatchedNotification}).
     */
    public function raise(CardPayment $record): void
    {
        match ($record->type) {
            CardPaymentType::Duplicate => $this->duplicate($record),
            CardPaymentType::Unmatched => $this->unmatched($record),
            CardPaymentType::Refund => $this->refund($record),
            CardPaymentType::Matched => null,
        };
    }

    public function duplicate(CardPayment $record): void
    {
        $this->send($record, 'duplicate', [
            'number' => $this->number($record),
            'amount' => $record->amount()->format('cs'),
        ]);
    }

    public function unmatched(CardPayment $record): void
    {
        $this->send($record, 'unmatched', [
            'amount' => $record->amount()->format('cs'),
            'reason' => $record->reason?->getLabel() ?? '',
        ]);
    }

    public function refund(CardPayment $record): void
    {
        $this->send($record, 'refund', [
            'amount' => $record->amount()->format('cs'),
        ]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function send(CardPayment $record, string $notice, array $replace): void
    {
        $title = (string) __('payment_notices.'.$notice.'.title');

        $this->alerter->alert('card-payment:'.$record->getKey(), new OperationalAlert(
            title: $title,
            body: (string) __('payment_notices.'.$notice.'.body', $replace),
            actionUrl: CardPaymentResource::getUrl('view', ['record' => $record]),
            actionLabel: (string) __('payment_notices.open_payment'),
            mailSubject: (string) __('payment_notices.mail_subject', ['title' => $title]),
        ));
    }

    /**
     * The number of the document the record belongs to, empty when the record has none.
     */
    private function number(CardPayment $record): string
    {
        if ($record->invoice_id === null) {
            return '';
        }

        $number = app(PartnerContext::class)->runAsSystem(
            static fn (): mixed => Invoice::query()->whereKey($record->invoice_id)->value('number'),
        );

        return is_string($number) ? $number : '';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Jobs\StripeEventAction;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Notifications\CardPaymentMatchedNotification;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Matches one paid Stripe checkout session to a document and records what it became (PA-06, PA-07, PA-08; D-10 to
 * D-14; rulings R11-13-2, R11-13-3; research Pattern 5, Pitfalls 6 and 11, Open Questions 1 and 6).
 *
 * It runs inside the transaction of {@see ProcessStripeWebhook}, which holds the lock of the
 * webhook row, so the lock order is: the webhook row, the document, the card payment record, the payment, the income.
 * Everything below is one transaction: a failure leaves nothing behind and the event stays unprocessed for a retry.
 *
 * Classification, in order (the first that holds decides):
 *  1. no Kokpit link for the session, or a link of another document than the metadata names: unmatched `undetermined`
 *     with no document (a payment of another product on the same Stripe account lands here, T-11-47);
 *  2. a document that is not an issued invoice or proforma: `undetermined`;
 *  3. a cancelled invoice: unmatched `cancelled`;
 *  4. nothing owed any more: duplicate `already_paid`;
 *  5. a currency other than the link's: unmatched `currency`;
 *  6. an amount other than the one the link offered, or other than the one still owed: unmatched `amount`;
 *  7. otherwise matched: the payment and its income through {@see RecordPayment::recordLocked()} with no actor, which
 *     also deactivates the link, and the bell notice to every Admin once the transaction has committed.
 *
 * The amount and the currency are the session's `amount_total` and `currency`, never the payer's presentment figures
 * (Pitfall 6). The record is claimed first with {@see CardPayment::recordOnce()}: its unique event, session and payment
 * intent make a repeat, the async event of a recorded session, and any event of a recorded payment intent end here
 * with nothing written, also after the matched payment was deleted (D-12, D-14).
 */
final readonly class RecordCardPayment implements StripeEventAction
{
    public function __construct(
        private RecordPayment $payments,
        private InvoiceBalances $balances,
    ) {}

    /**
     * @return CardPayment|null the record written now, or null when nothing was recorded (an unpaid session, an
     *                          unreadable one, or a repeat)
     *
     * @throws LogicException outside a database transaction
     */
    public function handle(StripeEvent $event): ?CardPayment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A card payment is recorded inside the transaction of the webhook job.');
        }

        if (! $event->isPaid() || $event->id === null || $event->sessionId === null
            || $event->amountMinor === null || $event->currency === null) {
            return null;
        }

        $link = $this->link($event);

        if (! $link instanceof PaymentLink) {
            return $this->record($event, null, null, CardPaymentType::Unmatched, CardPaymentReason::Undetermined);
        }

        if (! $this->namesThisLink($event, $link)) {
            return $this->record($event, $link, null, CardPaymentType::Unmatched, CardPaymentReason::Undetermined);
        }

        $locked = Invoice::query()->whereKey($link->invoice_id)->lockForUpdate()->first();

        if (! $locked instanceof Invoice) {
            return $this->record($event, $link, null, CardPaymentType::Unmatched, CardPaymentReason::Undetermined);
        }

        if ($locked->state !== InvoiceState::Issued || ! $locked->kind->carriesPayment()) {
            return $this->record($event, $link, $locked, CardPaymentType::Unmatched, CardPaymentReason::Undetermined);
        }

        if ($locked->isCancelled()) {
            return $this->record($event, $link, $locked, CardPaymentType::Unmatched, CardPaymentReason::Cancelled);
        }

        $remaining = $this->balances->for([(string) $locked->getKey()])[(string) $locked->getKey()]['remaining'] ?? 0;

        if ($remaining <= 0) {
            return $this->record($event, $link, $locked, CardPaymentType::Duplicate, CardPaymentReason::AlreadyPaid);
        }

        if ($event->currency !== $link->currency) {
            return $this->record($event, $link, $locked, CardPaymentType::Unmatched, CardPaymentReason::Currency);
        }

        if ($event->amountMinor !== $link->amount_minor || $event->amountMinor !== $remaining) {
            return $this->record($event, $link, $locked, CardPaymentType::Unmatched, CardPaymentReason::Amount);
        }

        return $this->match($event, $link, $locked);
    }

    /**
     * The link the session came from: by the row id the link carried as metadata, otherwise by the Stripe link id
     * (D-10). Neither names a link Kokpit created: null.
     */
    private function link(StripeEvent $event): ?PaymentLink
    {
        $link = $event->paymentLinkRow === null ? null : PaymentLink::query()->whereKey($event->paymentLinkRow)->first();

        if ($link instanceof PaymentLink || $event->paymentLinkId === null) {
            return $link;
        }

        return PaymentLink::query()->where('stripe_link_id', $event->paymentLinkId)->first();
    }

    /**
     * Whether the session agrees with the link it was found by: a document named by the metadata is the link's
     * document, and a Stripe link id on the session is the link's. A crafted session of another product cannot borrow
     * a Kokpit link by naming only some of its ids (T-11-47).
     */
    private function namesThisLink(StripeEvent $event, PaymentLink $link): bool
    {
        if ($event->invoiceId !== null && $event->invoiceId !== $link->invoice_id) {
            return false;
        }

        return $event->paymentLinkId === null || $link->stripe_link_id === null || $event->paymentLinkId === $link->stripe_link_id;
    }

    /**
     * The matched write: the record is claimed, then the payment and its income are booked with no person, then the
     * record points at the payment. The link is deactivated by the reconcile inside the payment write.
     */
    private function match(StripeEvent $event, PaymentLink $link, Invoice $locked): ?CardPayment
    {
        $record = $this->record($event, $link, $locked, CardPaymentType::Matched, null);

        if (! $record instanceof CardPayment) {
            return null;
        }

        $amount = Money::ofMinor((int) $event->amountMinor, $locked->currency);
        $paidOn = $this->paidOn($event);

        $payment = $this->payments->recordLocked($locked, new PaymentFacts($amount, $paidOn, PaymentMethod::Card, $paidOn, null, null), null);

        $record->forceFill(['invoice_payment_id' => $payment->getKey()])->save();

        $this->notify($locked, $amount);

        return $record;
    }

    /**
     * Claims the record of the event. Null means the event, the session or the payment intent is already recorded:
     * a repeat, which ends the handler with nothing written.
     */
    private function record(StripeEvent $event, ?PaymentLink $link, ?Invoice $document, CardPaymentType $type, ?CardPaymentReason $reason): ?CardPayment
    {
        return CardPayment::recordOnce([
            'type' => $type,
            'reason' => $reason,
            'amount_minor' => $event->amountMinor,
            'currency' => $event->currency,
            'offered_amount_minor' => $link?->amount_minor,
            'offered_currency' => $link?->currency,
            'stripe_event_id' => $event->id,
            'stripe_session_id' => $event->sessionId,
            'stripe_payment_intent_id' => $event->paymentIntentId,
            'stripe_link_id' => $event->paymentLinkId ?? $link?->stripe_link_id,
            'invoice_id' => $document?->getKey(),
            'payment_link_id' => $link?->getKey(),
            'received_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The Prague calendar day of the event, today when Stripe sent none (R11-13-3).
     */
    private function paidOn(StripeEvent $event): string
    {
        return $event->created === null
            ? IssueContext::today()
            : $event->created->setTimezone(FilamentTimezone::get())->format('Y-m-d');
    }

    /**
     * The bell notice to every Admin, after the transaction has committed, so a rolled-back match tells nobody. The
     * text holds the document number and the amount only (R11-13-4, T-11-48).
     */
    private function notify(Invoice $document, Money $amount): void
    {
        $notification = new CardPaymentMatchedNotification(
            number: (string) $document->number,
            amount: $amount->format('cs'),
            proforma: $document->kind === InvoiceKind::Proforma,
            url: InvoiceResource::getUrl('view', ['record' => $document->getKey()]),
        );

        DB::afterCommit(static function () use ($notification): void {
            foreach (User::query()->role(RoleName::Admin->value)->get() as $admin) {
                $admin->notifyNow($notification);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Jobs\StripeEventAction;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Payments\Support\CardPaymentAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Records a refund that Stripe announced (`refund.created`) and tells every Admin (PA-08; D-14; R11-16-3; research
 * Pitfall 11).
 *
 * Kokpit never refunds and never changes the books because of a refund: nothing is written but the refund record. The
 * record is linked to the card payment of the same payment intent, and through it to the document and the link, when
 * that payment is known. A refund that arrives before its payment is recorded stands alone with no document and stays
 * unresolved for the Admin. The unique event and refund ids of the record make a repeat end here with nothing written.
 *
 * It runs inside the transaction of {@see ProcessStripeWebhook}, which holds the lock of the webhook row; the document
 * of the related payment is locked after it, in the order of the other handlers. The notice goes out once the
 * transaction has committed, so a rolled-back record tells nobody.
 */
final readonly class RecordRefundEvent implements StripeEventAction
{
    public function __construct(private CardPaymentAlerts $alerts) {}

    /**
     * @return CardPayment|null the refund record written now, or null when nothing was recorded (an unreadable refund
     *                          or a repeat)
     *
     * @throws LogicException outside a database transaction
     */
    public function handle(StripeEvent $event): ?CardPayment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A refund event is recorded inside the transaction of the webhook job.');
        }

        if ($event->id === null || $event->refundId === null || $event->refundAmountMinor === null || $event->refundCurrency === null) {
            return null;
        }

        $payment = $event->paymentIntentId === null ? null : CardPayment::query()
            ->where('stripe_payment_intent_id', $event->paymentIntentId)
            ->where('type', '<>', CardPaymentType::Refund->value)
            ->first();

        $document = $payment?->invoice_id === null ? null : Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->first();

        $record = CardPayment::recordOnce([
            'type' => CardPaymentType::Refund,
            'reason' => null,
            'amount_minor' => $event->refundAmountMinor,
            'currency' => $event->refundCurrency,
            'stripe_event_id' => $event->id,
            'stripe_refund_id' => $event->refundId,
            'stripe_payment_intent_id' => $event->paymentIntentId,
            'invoice_id' => $document?->getKey(),
            'payment_link_id' => $payment?->payment_link_id,
            'received_at' => CarbonImmutable::now(),
        ]);

        if ($record instanceof CardPayment) {
            DB::afterCommit(fn () => $this->alerts->refund($record));
        }

        return $record;
    }
}

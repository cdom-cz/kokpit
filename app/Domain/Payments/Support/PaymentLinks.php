<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\DeactivatePaymentLinks;
use App\Domain\Payments\Models\PaymentLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one invariant of the payment links: a live link offers exactly what the document still owes (PA-04; D-08,
 * research Pattern 3).
 *
 * {@see PaymentLinks::reconcile()} is called from every transaction that changes what is owed (a payment, a credit
 * note, a storno, the deletion of a payment), after that change and with the document row locked, so the link and the
 * document move together or not at all. It never runs on its own: the lock order is document first, link second.
 *
 * Switching the link off at Stripe is not done here. A deactivated row that has a Stripe id and no
 * `remote_deactivated_at` is handed to {@see DeactivatePaymentLinks} after the commit, and the scheduled sweep of the
 * same job repairs a call that was lost.
 */
final readonly class PaymentLinks
{
    public function __construct(private InvoiceBalances $balances) {}

    /**
     * Deactivates the live link of the locked document when it no longer offers what is owed: `cancelled` after a
     * storno, `paid` when nothing is left, `amount_changed` when the remaining amount differs from the offered one.
     * Nothing is done when there is no live link or when it still offers the remaining amount.
     *
     * The balance is read inside the caller's transaction, so it sees the caller's uncommitted writes, and the
     * cancellation is asked of the database afresh, never from a cached attribute of the row.
     *
     * @throws LogicException outside a database transaction
     */
    public function reconcile(Invoice $locked): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PaymentLinks::reconcile() must run inside the caller\'s transaction, with the document locked.');
        }

        $link = PaymentLink::query()->live()->where('invoice_id', $locked->id)->lockForUpdate()->first();

        if (! $link instanceof PaymentLink) {
            return;
        }

        $reason = $this->staleReason($locked, $link);

        if ($reason !== null) {
            $this->deactivate($link, $reason);
        }
    }

    /**
     * Why the link no longer offers what is owed, or null when it still does.
     */
    private function staleReason(Invoice $locked, PaymentLink $link): ?PaymentLinkDeactivation
    {
        if ($locked->stornoCreditNote()->exists()) {
            return PaymentLinkDeactivation::Cancelled;
        }

        $remaining = $this->balances->for([$locked->id])[$locked->id]['remaining'] ?? 0;

        if ($remaining <= 0) {
            return PaymentLinkDeactivation::Paid;
        }

        return $remaining === $link->amount_minor ? null : PaymentLinkDeactivation::AmountChanged;
    }

    /**
     * Deactivates a live link locally, with its reason, and queues its deactivation at Stripe for after the commit
     * when the row already has a Stripe id. A pending row without one is left to its own job, which finds the
     * deactivation in its second lock (plan 11-07). The caller holds the lock of the document and of the row.
     *
     * @throws LogicException outside a database transaction
     */
    public function deactivate(PaymentLink $link, PaymentLinkDeactivation $reason): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A payment link is deactivated inside the caller\'s transaction, with the document locked.');
        }

        $link->forceFill([
            'state' => PaymentLinkState::Deactivated,
            'deactivation_reason' => $reason,
            'deactivated_at' => CarbonImmutable::now(),
        ])->save();

        if ($link->stripe_link_id !== null) {
            DeactivatePaymentLinks::dispatch($link->id)->afterCommit();
        }
    }
}

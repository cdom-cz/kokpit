<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancels (storno) a cash receipt with a recorded reason (Phase 15; D-08, D-11, D-13; P15-10, P15-12; AS-1, AS-16). The
 * Admin only; a Partner gets the base denial of the policy before anything else happens.
 *
 * A cancel marks the receipt void for good: it keeps its number (the number is never reused or returned, so the series
 * stays gap-free as a record), it keeps its stored PDF (the evidence), and it is never restored. Cancelling is the only
 * way to make a payment with a receipt deletable: {@see DeletePayment} refuses while the receipt is valid, and after the
 * cancel the database clears the payment link of the receipt when the payment row goes.
 *
 * The reason (10 to 500 characters, as {@see UnlockInvoice}) is free text typed by the Admin. It goes to the Admin-only
 * activity log ({@see CashReceiptAudit::cancelled()}) and nowhere else: the receipt row, which a Partner reads, has no
 * column for it (15-02 decision, the Phase 10.1 precedent, owner confirmation AS-16).
 *
 * Everything happens in one database transaction. The order is deliberate:
 *
 * 1. the actor is authorised and the reason is trimmed and measured before the database is touched;
 * 2. the rows are locked in the order every payment writer uses: the invoice, then the payment (when the receipt still
 *    has one), then the receipt; a receipt that is cancelled already is refused on the locked row (`already_cancelled`);
 * 3. `cancelled_at` is set once through a model save, a single statement that sets only that column and `updated_at`,
 *    which is all the row guard of the database accepts for a cancel, in an open and in a locked receipt alike;
 * 4. the audit row is written, then {@see StoreCashReceiptPdf} renders the receipt again, now with the cancelled band,
 *    and replaces the stored PDF, all in this transaction, so a failing render cancels nothing.
 *
 * Race with the send: the confirmation job ({@see SendPaymentConfirmation}) takes only the receipt row lock, while the
 * cancel takes the invoice, payment and receipt locks in that order, so the two serialise on the receipt row and cannot
 * deadlock. Whoever comes second sees the other's result: a receipt cancelled before the send leaves is not attached and
 * not locked by it (the confirmation itself is still sent, AS-1); a receipt locked by the send is cancelled all the same.
 * No confirmation row is touched here.
 */
final readonly class CancelCashReceipt
{
    public const int REASON_MIN = 10;

    public const int REASON_MAX = 500;

    public function __construct(private StoreCashReceiptPdf $storePdf) {}

    /**
     * @throws ValidationException `reason` for a reason outside 10 to 500 characters; `receipt` for a receipt that is
     *                             cancelled already
     */
    public function handle(User $actor, CashReceipt $receipt, string $reason): CashReceipt
    {
        Gate::forUser($actor)->authorize('update', $receipt);

        $clean = trim($reason);
        $length = mb_strlen($clean);

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => __('cash_receipts.errors.reason_length')]);
        }

        return DB::transaction(function () use ($actor, $receipt, $clean): CashReceipt {
            Invoice::query()->whereKey($receipt->invoice_id)->lockForUpdate()->firstOrFail();

            if ($receipt->invoice_payment_id !== null) {
                InvoicePayment::query()->whereKey($receipt->invoice_payment_id)->lockForUpdate()->first();
            }

            $locked = CashReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['receipt' => __('cash_receipts.errors.already_cancelled')]);
            }

            $locked->forceFill(['cancelled_at' => now()])->save();

            CashReceiptAudit::cancelled($locked, $actor, $clean);

            $this->storePdf->handle($actor, $locked->refresh());

            return $locked->refresh();
        });
    }
}

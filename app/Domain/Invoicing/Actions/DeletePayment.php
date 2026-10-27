<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Payments\Support\PaymentLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a mistaken payment of an issued invoice or proforma together with the income booked from it (IN-05, IN-06,
 * FI-03; D-19 as amended by the owner on 2026-10-17, D-13, D-15, D-16, D-17, D-20; rulings R-41-1 to R-41-5). The
 * Admin only, and the only path that deletes a payment or the income of one: there is no payment edit, and
 * {@see DeleteTransaction} refuses payment income.
 *
 * Everything happens in one database transaction. The order is deliberate:
 *
 * 1. the document row is locked first, the lock order shared with {@see RecordPayment}, {@see ConvertProforma} and
 *    {@see CancelCashReceipt} (document, payment, receipt, income), so a deletion, a conversion, a cancel and a new
 *    payment of one document serialise on that row; a row lock fires no UPDATE trigger, so the frozen guard of the
 *    issued document is not touched;
 * 2. the payment is read again under its own lock (gone: `payment_missing`), and a payment that is the source of a
 *    carried copy on an invoice is refused (`payment_delete_carried`), both before anything else is changed;
 * 2b. a payment with a receipt that is not cancelled is refused (`payment_has_receipt`, Phase 15, D-08): the Admin
 *    cancels the receipt first ({@see CancelCashReceipt}); the receipt and its PDF are never deleted here;
 * 3. the income is locked, then the transaction-local flag `kokpit.payment_deletion` names this very payment, the
 *    only thing the payment guard and the payment-income guard of plan 10-06 accept;
 * 4. the receipts of the income go through {@see DeleteDocument} (their stored objects are removed after the commit
 *    and never when this transaction rolls back), then the income, then the payment, each through its model so the
 *    `deleted` activity is written with allowlisted attributes only. The income goes first because the key from
 *    `transactions` to `invoice_payments` is NO ACTION; the deferred pair check of the database then finds the
 *    payment gone at commit;
 * 5. the flag is cleared again, so it never outlives the sanctioned statements, even inside a wrapping transaction.
 *
 * Nothing is written on the `invoices` row: the status, the remaining amount and the overdue state of the document
 * are derived from the remaining payments ({@see InvoiceBalances}, {@see ReceivablesReader}). A proforma payment has
 * no income to delete (D-18). Deleting the invoice-side copy of a carried payment never touches its proforma source
 * (D-15). A cancelled invoice keeps its payments correctable (D-16). No bell notice is sent, because the Admin
 * performs the deletion.
 */
final readonly class DeletePayment
{
    public function __construct(
        private DeleteDocument $deleteDocument,
        private PaymentLinks $paymentLinks,
    ) {}

    /**
     * @throws ValidationException `payment_missing`, `payment_delete_carried` or `payment_has_receipt`, all under `payment`
     */
    public function handle(User $actor, InvoicePayment $payment): void
    {
        Gate::forUser($actor)->authorize('delete', $payment);

        DB::transaction(function () use ($actor, $payment): void {
            $document = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            $locked = InvoicePayment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if (! $locked instanceof InvoicePayment) {
                throw ValidationException::withMessages(['payment' => __('invoices.errors.payment_missing')]);
            }

            $copy = InvoicePayment::query()->where('carried_from_payment_id', $locked->id)->first();

            if ($copy instanceof InvoicePayment) {
                throw ValidationException::withMessages([
                    'payment' => __('invoices.errors.payment_delete_carried', ['number' => (string) Invoice::query()->whereKey($copy->invoice_id)->value('number')]),
                ]);
            }

            // D-08: a payment whose cash receipt is valid is refused; the Admin cancels the receipt first. A cancelled
            // receipt is left alone: the key from the receipt to the payment clears its link when the payment goes, and
            // neither the receipt nor its PDF is deleted.
            $receipt = CashReceipt::query()->where('invoice_payment_id', $locked->id)->lockForUpdate()->first();

            if ($receipt instanceof CashReceipt && ! $receipt->isCancelled()) {
                throw ValidationException::withMessages([
                    'payment' => __('cash_receipts.errors.payment_has_receipt', ['number' => $receipt->number]),
                ]);
            }

            $income = Transaction::query()->where('invoice_payment_id', $locked->id)->lockForUpdate()->first();

            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$locked->id]);

            if ($income instanceof Transaction) {
                foreach ($income->documents()->orderBy('id')->get() as $receipt) {
                    $this->deleteDocument->handle($actor, $receipt);
                }

                $income->delete();
            }

            $locked->delete();

            DB::select("select set_config('kokpit.payment_deletion', '', true)");

            // D-08: the deleted payment raises what is owed, so the live payment link that offered less is deactivated here.
            $this->paymentLinks->reconcile($document);
        });
    }
}

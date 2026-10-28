<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoiceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Locks an issued, open invoice or proforma by hand, without any e-mail (Phase 16, D-02, D-03; owner answer A2). It is
 * for the document that reached the customer another way, for example a client with the document e-mails switched off
 * who is handed the document in person. The Admin only, for every client, with no reason: the confirmation in the page
 * is the whole question, and the audit row ({@see InvoiceAudit::lockedManually()}) records who locked it and when.
 *
 * D-02 is rated costly in the phase context: this is the owner-approved second writer of the locked state, next to the
 * successful send of a document e-mail ({@see SendInvoiceEmail}) and the issue of a credit note. The architecture test
 * `InvoiceUnlockPathTest` names all of them. The lock writes no `invoice_emails` row and no `sent_at`: "sent" keeps
 * meaning a delivered e-mail, and the number, the kind and every snapshot stay as they are. The unlock
 * ({@see UnlockInvoice}) is unchanged and reopens a document locked this way like one locked by a send.
 *
 * Everything happens in one database transaction, in the lock order of every writer of the document: the invoice row
 * is locked first and the refusals are judged on that locked row, so a lock by hand and a lock by a send never
 * interleave: whichever takes the row second finds the document locked and leaves it as it is (the send job marks its
 * e-mail sent and writes no second lock row; this Action refuses with `already_locked`). A queued or sending e-mail
 * does not block the lock, because locking is always safe.
 *
 * The state changes through a model save, never a bulk query update, so the row guard of the database sees one change
 * of `lock_state` and `updated_at` on an open row, which it allows. A Partner is refused by the policy before anything
 * else happens.
 */
final readonly class LockInvoice
{
    /**
     * @throws ValidationException `lock` for a document that cannot be locked by hand
     */
    public function handle(User $actor, Invoice $invoice): Invoice
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return DB::transaction(function () use ($actor, $invoice): Invoice {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $this->refuse($locked);

            $locked->forceFill(['lock_state' => InvoiceLockState::Locked])->save();

            InvoiceAudit::lockedManually($locked, $actor);

            return $locked->refresh();
        });
    }

    /**
     * Throws for a document the manual lock must not close: a draft, a credit note (locked at its issue), a document
     * that is locked already, and the two the unlock could not reopen either, a cancelled invoice and a proforma that
     * already has its invoice (owner answer A2).
     *
     * @throws ValidationException
     */
    private function refuse(Invoice $locked): void
    {
        $message = match (true) {
            $locked->isDraft() => 'not_issued',
            $locked->kind === InvoiceKind::CreditNote => 'credit_note_locked',
            $locked->isLocked() => 'already_locked',
            $locked->isCancelled() => 'cancelled_not_editable',
            $locked->kind === InvoiceKind::Proforma && $locked->invoiceFromProforma()->exists() => 'converted_not_editable',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['lock' => __('invoices.errors.'.$message)]);
        }
    }
}

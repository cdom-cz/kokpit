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
 * Opens a sent (locked) invoice or proforma again so the Admin can correct it (IN-12, IN-15; D-03, D-13; rulings
 * R-10.1-02-1 and R-10.1-02-2; assumptions A1, A3, A12). The Admin only, with a reason of 10 to 500 characters, and the
 * only code that sets `kokpit.invoice_unlock`: the architecture test `InvoiceUnlockPathTest` keeps that literal out of
 * every other application file and migration. There is no automatic relock and no one-edit relock: the document stays
 * open until the next successful e-mail send ({@see SendInvoiceEmail}) locks it again.
 *
 * Everything happens in one database transaction. The order is deliberate:
 *
 * 1. the actor is authorised and the reason is trimmed and measured before the database is touched;
 * 2. the invoice row is locked first, the lock order of every edit of the document (invoice, then children), and the
 *    refusals are judged on that locked row: a draft or a document that is not locked (`not_locked`), a credit note
 *    (`credit_note_locked`, assumption A1), a cancelled invoice (`cancelled_not_editable`) and a proforma that
 *    already has its invoice (`converted_not_editable`), the last two because nobody could edit them even when open
 *    (assumption A3);
 * 3. the transaction-local setting `kokpit.invoice_unlock` names this very document, the only thing the row guard of
 *    the database accepts for the move from locked to open;
 * 4. the document is opened through a model save, never a bulk query update, so the guard sees one row change;
 * 5. the setting is cleared again, so it never outlives the sanctioned statement, even inside a wrapping transaction;
 * 6. the audit row ({@see InvoiceAudit::unlocked()}) records the actor, the time and the reason.
 *
 * A Partner is refused by the policy before anything else happens.
 */
final readonly class UnlockInvoice
{
    public const int REASON_MIN = 10;

    public const int REASON_MAX = 500;

    /**
     * @throws ValidationException `reason` for a reason outside 10 to 500 characters; `unlock` for a document that
     *                             cannot be unlocked
     */
    public function handle(User $actor, Invoice $invoice, string $reason): Invoice
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        $clean = trim($reason);
        $length = mb_strlen($clean);

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => __('invoices.errors.unlock_reason_length')]);
        }

        return DB::transaction(function () use ($actor, $invoice, $clean): Invoice {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $this->refuse($locked);

            DB::select("select set_config('kokpit.invoice_unlock', ?, true)", [$locked->id]);

            $locked->forceFill(['lock_state' => InvoiceLockState::Open])->save();

            DB::select("select set_config('kokpit.invoice_unlock', '', true)");

            InvoiceAudit::unlocked($locked, $actor, $clean);

            return $locked->refresh();
        });
    }

    /**
     * Throws for a document the unlock must not open (assumptions A1 and A3).
     *
     * @throws ValidationException
     */
    private function refuse(Invoice $locked): void
    {
        $message = match (true) {
            $locked->isDraft() => 'not_locked',
            $locked->kind === InvoiceKind::CreditNote => 'credit_note_locked',
            ! $locked->isLocked() => 'not_locked',
            $locked->isCancelled() => 'cancelled_not_editable',
            $locked->kind === InvoiceKind::Proforma && $locked->invoiceFromProforma()->exists() => 'converted_not_editable',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['unlock' => __('invoices.errors.'.$message)]);
        }
    }
}

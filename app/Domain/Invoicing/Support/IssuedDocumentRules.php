<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Validation\ValidationException;

/**
 * The one rule that says whether an issued document may be corrected (D-01, D-04; assumptions A1 and A3; R-10.1-04-2).
 * The write path ({@see UpdateIssuedDocument}) asks it under the row lock, the pages and
 * the later plans ask it to decide what to offer; none of them names the lock state themselves.
 *
 * {@see IssuedDocumentRules::editBlocker()} returns the first reason a document cannot be edited, as the key of
 * `invoices.errors`, or null when it can:
 *
 * - `not_issued`: a draft, which is edited in the draft editor;
 * - `credit_note_locked`: a credit note, never edited after its issue (A1);
 * - `document_locked`: the document was sent and is locked until the Admin unlocks it (D-01);
 * - `cancelled_not_editable`: an issued storno credit note names the invoice (A3);
 * - `converted_not_editable`: the proforma already has its invoice (A3).
 *
 * Payments and credit notes are not reasons: an invoice with money already received or credited stays correctable
 * (D-04), the amounts only warn.
 */
final class IssuedDocumentRules
{
    /**
     * The first reason the document cannot be edited, or null when it is an open issued invoice or proforma.
     */
    public static function editBlocker(Invoice $invoice): ?string
    {
        return match (true) {
            ! $invoice->isIssued() => 'not_issued',
            $invoice->kind === InvoiceKind::CreditNote => 'credit_note_locked',
            $invoice->isLocked() => 'document_locked',
            $invoice->isCancelled() => 'cancelled_not_editable',
            $invoice->kind === InvoiceKind::Proforma && $invoice->invoiceFromProforma()->exists() => 'converted_not_editable',
            default => null,
        };
    }

    /**
     * Throws when the (locked) document cannot be edited.
     *
     * @throws ValidationException `document` with the copy of the reason
     */
    public static function assertEditable(Invoice $locked): void
    {
        $reason = self::editBlocker($locked);

        if ($reason !== null) {
            throw ValidationException::withMessages(['document' => __('invoices.errors.'.$reason)]);
        }
    }
}

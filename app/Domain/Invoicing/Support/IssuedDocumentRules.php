<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
 * - `converted_not_editable`: the proforma already has its invoice (A3);
 * - `email_in_flight`: an e-mail of the document is queued, or sending and younger than the stale threshold of the
 *   System page (A8, R-10.1-04-2). A queued or sending e-mail does not lock the document (D-01), so the edit waits for
 *   it instead: the customer must not receive a PDF that differs from the stored one. An older sending row counts as
 *   failed and a failed row is not in flight, so nothing blocks forever.
 *
 * Payments and credit notes are not reasons: an invoice with money already received or credited stays correctable
 * (D-04), {@see IssuedDocumentRules::warnings()} only reports when the total falls below them.
 *
 * The e-mail log is Admin-only, so the in-flight reason is read in an Admin or system context, which is where every
 * edit and every page that offers one runs.
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
            self::emailInFlight($invoice) => 'email_in_flight',
            default => null,
        };
    }

    /**
     * What is odd about the amounts after an edit, without blocking it (D-04): `total_below_paid` carries the paid
     * amount when more was paid than the document now totals, `total_below_credited` the credited amount when more was
     * credited. Both come from the stored payment and credit note rows through {@see InvoiceBalances}, so the paid
     * state and the remaining amount follow on their own. A draft, a credit note and a reader that is not the Admin
     * get an empty list; the method never throws.
     *
     * @return array<string, Money>
     */
    public static function warnings(Invoice $invoice): array
    {
        if (! $invoice->isIssued() || $invoice->kind === InvoiceKind::CreditNote) {
            return [];
        }

        try {
            $balance = app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id] ?? null;
        } catch (AuthorizationException) {
            return [];
        }

        if ($balance === null) {
            return [];
        }

        $warnings = [];

        if ($balance['paid'] > $invoice->total_minor) {
            $warnings['total_below_paid'] = Money::ofMinor($balance['paid'], $invoice->currency);
        }

        if ($balance['credited'] > $invoice->total_minor) {
            $warnings['total_below_credited'] = Money::ofMinor($balance['credited'], $invoice->currency);
        }

        return $warnings;
    }

    /**
     * The ids of the lines of the document that a credit note line names, draft or issued (assumption A4): such a line
     * keeps its quantity, unit and unit price and cannot be deleted. The write path and the editor ask here, so both
     * see the same lines.
     *
     * @return array<string, true> the ids as keys
     */
    public static function creditedLineIds(Invoice $invoice): array
    {
        /** @var array<string, true> $credited */
        $credited = InvoiceItem::query()
            ->whereIn('credited_item_id', InvoiceItem::query()->where('invoice_id', $invoice->id)->select('id'))
            ->distinct()
            ->pluck('credited_item_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();

        return $credited;
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

    /**
     * Whether an e-mail of the document is queued, or sending and younger than the stale threshold (A8). The age of a
     * sending row runs from its last move, the same reading as the System page.
     */
    private static function emailInFlight(Invoice $invoice): bool
    {
        $staleBefore = CarbonImmutable::now()->subSeconds(max(1, (int) config('kokpit.health.unsent_invoice_email_stale_after')));

        return InvoiceEmail::query()
            ->where('invoice_id', $invoice->id)
            ->where(static function (Builder $query) use ($staleBefore): void {
                $query->where('state', InvoiceEmailState::Queued->value)
                    ->orWhere(static function (Builder $sending) use ($staleBefore): void {
                        $sending->where('state', InvoiceEmailState::Sending->value)->where('updated_at', '>', $staleBefore);
                    });
            })
            ->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * How much of each line of an invoice can still be credited (D-14, research assumption A10): the original quantity
 * less the quantities that issued credit notes already took back, line by line through `credited_item_id`.
 *
 * Only issued credit notes count: a draft credit note is not a fact yet, and the partial and the storno credit notes
 * are treated alike, because both take quantities back. The answer is exact (sums of ten-thousandths) and is only
 * meaningful while the invoice row is locked, so two credit notes issued at the same moment cannot both claim the
 * same quantity (T-10-61); the caller holds that lock and this class refuses to run outside a transaction.
 *
 * A line with nothing left is in the result with a zero quantity, so a caller can tell "a line of this invoice with
 * nothing left to credit" from "not a line of this invoice".
 */
final class CreditableQuantities
{
    /**
     * The remaining creditable quantity of every line of the invoice, keyed by the id of the line.
     *
     * @return array<string, InvoiceQuantity>
     *
     * @throws LogicException outside a transaction
     */
    public static function for(Invoice $lockedInvoice): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The creditable quantities are read under the row lock of the invoice, inside a transaction.');
        }

        /** @var array<string, numeric-string> $credited */
        $credited = InvoiceItem::query()
            ->join('invoices as credit_notes', 'credit_notes.id', '=', 'invoice_items.invoice_id')
            ->where('credit_notes.credited_invoice_id', $lockedInvoice->id)
            ->where('credit_notes.kind', InvoiceKind::CreditNote->value)
            ->where('credit_notes.state', InvoiceState::Issued->value)
            ->whereNotNull('invoice_items.credited_item_id')
            ->groupBy('invoice_items.credited_item_id')
            ->selectRaw('invoice_items.credited_item_id AS item_id, SUM(invoice_items.quantity) AS quantity')
            ->toBase()
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->item_id => (string) $row->quantity])
            ->all();

        $remaining = [];

        /** @var iterable<InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $lockedInvoice->id)->orderBy('position')->get();

        foreach ($items as $item) {
            $original = $item->quantity();

            // The credit lines carry negative quantities, so their sum is added to the original.
            $left = isset($credited[$item->id]) ? $original->plus(InvoiceQuantity::fromDecimal($credited[$item->id])) : $original;

            $remaining[$item->id] = $original->isPositive() && $left->isPositive() ? $left : InvoiceQuantity::fromDecimal('0');
        }

        return $remaining;
    }
}

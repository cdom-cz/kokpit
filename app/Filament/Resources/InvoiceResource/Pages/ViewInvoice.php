<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Filament\Resources\InvoiceResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * The first document page (ruling R-21-1): number, kind, state, client and the frozen lines, read only. A draft row of
 * the list opens the editor ({@see EditInvoice}) since plan 10-25. Later plans add the actions, the payments and the
 * related documents of UI-SPEC Surface E.
 */
final class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up. The Partner scope of a document would otherwise
     * serve an own issued invoice through the policy, and this is not a Partner screen.
     */
    public function mount(int|string $record): void
    {
        abort_unless(InvoiceResource::canAccess(), 403);

        parent::mount($record);
    }

    public function getTitle(): string
    {
        $invoice = $this->getRecord();
        assert($invoice instanceof Invoice);

        if ($invoice->number !== null) {
            return $invoice->number;
        }

        return match ($invoice->kind) {
            InvoiceKind::Proforma => __('invoices.navigation.draft_proforma'),
            InvoiceKind::CreditNote => __('invoices.navigation.draft_credit_note'),
            InvoiceKind::Invoice => __('invoices.navigation.draft_invoice'),
        };
    }
}

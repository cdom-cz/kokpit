<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use App\Domain\Invoicing\Queries\ReceivablesReader;
use Filament\Support\Contracts\HasLabel;

/**
 * The tabs of the invoice list (UI-SPEC Surface A, ruling R-19-3). Each tab is a filter over the derived receivables
 * state, defined once in {@see ReceivablesReader}, so a tab and its count can never
 * disagree.
 *
 * - all: every document, drafts and credit notes included (a credit note appears here only);
 * - drafts: documents not issued yet;
 * - unpaid: issued invoice or proforma, not cancelled, something remaining, not yet past its due date;
 * - overdue: the same, with the due date before today in Europe/Prague;
 * - paid: nothing remaining, not cancelled (a converted proforma and a paid proforma awaiting its invoice included);
 * - cancelled: an issued credit note cancels the invoice;
 * - awaiting_invoice: a paid proforma no issued invoice was made from (D-18).
 */
enum InvoiceListTab: string implements HasLabel
{
    case All = 'all';
    case Drafts = 'drafts';
    case Unpaid = 'unpaid';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case AwaitingInvoice = 'awaiting_invoice';

    public function getLabel(): string
    {
        return __('enums.invoice_list_tab.'.$this->value);
    }
}

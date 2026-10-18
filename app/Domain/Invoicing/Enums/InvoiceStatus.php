<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * How a document reads on the screens: the derived state (D-18, D-20, IN-09).
 *
 * It is a display state. The stored `InvoiceState` has two values; this enum is derived from
 * payments, due date and links between documents. The Admin label says everything; the Partner
 * label hides what the client has no business with (a paid proforma reads as paid).
 */
enum InvoiceStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Converted = 'converted';
    case AwaitingInvoice = 'awaiting_invoice';
    case CreditNote = 'credit_note';

    public function getLabel(): string
    {
        return __('enums.invoice_status.'.$this->value);
    }

    /**
     * The wording for a Partner. A draft never reaches a Partner surface (the Partner scope returns
     * issued documents only), so asking for its Partner label is a programming error.
     */
    public function partnerLabel(): string
    {
        if ($this === self::Draft) {
            throw new LogicException('A draft has no Partner label; the Partner never sees a draft.');
        }

        return __('enums.invoice_status_partner.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled, self::Converted, self::CreditNote => 'gray',
            self::Unpaid, self::PartiallyPaid => 'info',
            self::Overdue, self::AwaitingInvoice => 'danger',
            self::Paid => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencilSquare,
            self::Unpaid => Heroicon::OutlinedClock,
            self::PartiallyPaid => Heroicon::OutlinedBanknotes,
            self::Overdue, self::AwaitingInvoice => Heroicon::OutlinedExclamationTriangle,
            self::Paid => Heroicon::OutlinedCheckCircle,
            self::Cancelled => Heroicon::OutlinedXCircle,
            self::Converted => Heroicon::OutlinedArrowRightCircle,
            self::CreditNote => Heroicon::OutlinedReceiptRefund,
        };
    }
}

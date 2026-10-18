<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use App\Domain\Settings\Numbering\DocumentKind;
use Filament\Support\Contracts\HasLabel;

/**
 * The kind of a billing document (D-10, IN-01).
 *
 * Each kind has its own number series: an invoice, a proforma and a credit note never share a counter.
 */
enum InvoiceKind: string implements HasLabel
{
    case Invoice = 'invoice';
    case Proforma = 'proforma';
    case CreditNote = 'credit_note';

    /**
     * The numbering series of this kind. The numbering enum also holds the task series, so it is a
     * separate type.
     */
    public function numberingKind(): DocumentKind
    {
        return match ($this) {
            self::Invoice => DocumentKind::Invoice,
            self::Proforma => DocumentKind::Proforma,
            self::CreditNote => DocumentKind::CreditNote,
        };
    }

    /**
     * Whether a payment can be recorded against the document. A credit note reduces a debt; it is not paid.
     */
    public function carriesPayment(): bool
    {
        return $this !== self::CreditNote;
    }

    public function getLabel(): string
    {
        return __('enums.invoice_kind.'.$this->value);
    }
}

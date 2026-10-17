<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The stored state of a document: a draft or issued. Every other state (unpaid, overdue, paid,
 * cancelled, ...) is derived from payments, dates and links, never stored.
 */
enum InvoiceState: string implements HasLabel
{
    case Draft = 'draft';
    case Issued = 'issued';

    public function getLabel(): string
    {
        return __('enums.invoice_state.'.$this->value);
    }
}

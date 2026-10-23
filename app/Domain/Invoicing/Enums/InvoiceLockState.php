<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The lock of a document (IN-11; D-01, D-12): `Open` while its content may still be corrected, `Locked` once it
 * has been sent, or from its own issue for a credit note. Stored in `invoices.lock_state` next to the state, which
 * keeps meaning "numbered or not": a draft is always open, an issued invoice or proforma is open until a
 * successfully sent document e-mail locks it, and a credit note is locked at issue and never opened again.
 */
enum InvoiceLockState: string implements HasLabel
{
    case Open = 'open';
    case Locked = 'locked';

    public function getLabel(): string
    {
        return __('enums.invoice_lock_state.'.$this->value);
    }
}

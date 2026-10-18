<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a line of a document came from (D-07): billed time, a fixed amount, or a line typed by hand.
 */
enum InvoiceItemOrigin: string implements HasLabel
{
    case Time = 'time';
    case FixedAmount = 'fixed_amount';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return __('enums.invoice_item_origin.'.$this->value);
    }
}

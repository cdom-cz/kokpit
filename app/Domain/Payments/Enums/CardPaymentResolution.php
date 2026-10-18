<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How the Admin settled a duplicate, unmatched or refund record (D-13): assigned to a document, closed with a note, or
 * the money returned and entered as an expense. It moves once from `none` and is final; a matched record has none, a
 * refund can only be noted.
 */
enum CardPaymentResolution: string implements HasLabel
{
    case None = 'none';
    case Assigned = 'assigned';
    case Noted = 'noted';
    case Returned = 'returned';

    public function getLabel(): string
    {
        return __('enums.card_payment_resolution.'.$this->value);
    }
}

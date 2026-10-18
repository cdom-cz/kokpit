<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a payment link was deactivated (D-08): the document got paid or cancelled, a partial payment changed the
 * amount it offers, the Admin switched it off by hand, or a new link replaced it.
 */
enum PaymentLinkDeactivation: string implements HasLabel
{
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case AmountChanged = 'amount_changed';
    case Manual = 'manual';
    case Replaced = 'replaced';

    public function getLabel(): string
    {
        return __('enums.payment_link_deactivation.'.$this->value);
    }
}

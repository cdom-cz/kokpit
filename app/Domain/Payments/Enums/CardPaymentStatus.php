<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a card payment record stands for the Admin (UI-SPEC colour map). Derived from the record, never stored.
 */
enum CardPaymentStatus: string implements HasLabel
{
    case Booked = 'booked';
    case PaymentDeleted = 'payment_deleted';
    case Unresolved = 'unresolved';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return __('enums.card_payment_status.'.$this->value);
    }
}

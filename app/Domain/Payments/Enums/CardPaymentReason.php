<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a card payment was not booked (D-11, D-12): a duplicate carries `already_paid`; an unmatched payment carries
 * one of the other four. A matched payment and a refund have no reason.
 */
enum CardPaymentReason: string implements HasLabel
{
    case Amount = 'amount';
    case Currency = 'currency';
    case AlreadyPaid = 'already_paid';
    case Cancelled = 'cancelled';
    case Undetermined = 'undetermined';

    public function getLabel(): string
    {
        return __('enums.card_payment_reason.'.$this->value);
    }
}

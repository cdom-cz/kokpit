<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a Stripe event became (PA-06, PA-08; D-11, D-12): a payment matched to its document, a duplicate payment of a
 * document that was already paid, a payment that could not be matched (the reason says why) or a refund.
 */
enum CardPaymentType: string implements HasLabel
{
    case Matched = 'matched';
    case Duplicate = 'duplicate';
    case Unmatched = 'unmatched';
    case Refund = 'refund';

    public function getLabel(): string
    {
        return __('enums.card_payment_type.'.$this->value);
    }
}

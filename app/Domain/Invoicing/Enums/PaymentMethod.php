<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a payment reached the account (D-17): a bank transfer or cash by hand, or a card payment through Stripe.
 *
 * The card method is never chosen by a person: it is written only by the system path of a matched Stripe payment
 * (and by the Admin's assignment of one), so {@see PaymentMethod::manual()} lists the two methods a person may pick.
 */
enum PaymentMethod: string implements HasLabel
{
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Card = 'card';

    /**
     * The methods an Admin may choose when recording a payment by hand.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Transfer, self::Cash];
    }

    public function isManual(): bool
    {
        return $this !== self::Card;
    }

    public function getLabel(): string
    {
        return __('enums.payment_method.'.$this->value);
    }
}

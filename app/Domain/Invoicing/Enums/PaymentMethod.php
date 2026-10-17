<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a payment reached the account (D-17): a bank transfer or cash.
 */
enum PaymentMethod: string implements HasLabel
{
    case Transfer = 'transfer';
    case Cash = 'cash';

    public function getLabel(): string
    {
        return __('enums.payment_method.'.$this->value);
    }
}

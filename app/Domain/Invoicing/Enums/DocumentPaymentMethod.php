<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How one invoice or proforma is paid (Phase 14; D-01, D-02): `Transfer` prints the bank details and the customer pays
 * the account, `Online` is paid by card through the stable pay link and prints no bank details. Stored in
 * `invoices.payment_method`; a credit note has no method (null). A new draft is always `Transfer`.
 *
 * Not the recorded-payment enum {@see PaymentMethod} (transfer, cash, card): that one says how a received payment
 * arrived, this one says how the customer is asked to pay.
 */
enum DocumentPaymentMethod: string implements HasLabel
{
    case Transfer = 'transfer';
    case Online = 'online';

    public function getLabel(): string
    {
        return __('enums.document_payment_method.'.$this->value);
    }
}

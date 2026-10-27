<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How one invoice or proforma is paid (Phase 14; D-01, D-02): `Transfer` prints the bank details and the customer pays
 * the account, `Online` is paid by card through the stable pay link and prints no bank details. Phase 15 (D-01, D-02,
 * D-03) adds `Cash`: the customer pays in cash, the document prints no bank details and no payment instruction, and
 * it excludes card payment. Stored in `invoices.payment_method`; a credit note has no method (null). A new draft is
 * always `Transfer`.
 *
 * Three predicates carry the rules that depend on the method, so no caller compares the cases one by one:
 * {@see DocumentPaymentMethod::printsBankDetails()} (a bank snapshot is stored and printed),
 * {@see DocumentPaymentMethod::allowsCardChoice()} (the Admin decides the card switch) and
 * {@see DocumentPaymentMethod::forcesCardPayment()} (the card switch is forced on). A method that answers false to all
 * three, which is `Cash`, has no bank details and a card switch that stays off.
 *
 * Not the recorded-payment enum {@see PaymentMethod} (transfer, cash, card): that one says how a received payment
 * arrived, this one says how the customer is asked to pay.
 */
enum DocumentPaymentMethod: string implements HasLabel
{
    case Transfer = 'transfer';
    case Online = 'online';
    case Cash = 'cash';

    /** True when the document stores and prints a bank snapshot: only a bank transfer (D-02). */
    public function printsBankDetails(): bool
    {
        return $this === self::Transfer;
    }

    /** True when the Admin decides the card switch of the document: only a bank transfer (D-03). */
    public function allowsCardChoice(): bool
    {
        return $this === self::Transfer;
    }

    /** True when the card switch is forced on: only an online document (Phase 14 D-03). */
    public function forcesCardPayment(): bool
    {
        return $this === self::Online;
    }

    public function getLabel(): string
    {
        return __('enums.document_payment_method.'.$this->value);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Shared\Money\Money;

/**
 * The already-checked facts of one payment, the input of {@see RecordPayment::recordLocked()}.
 *
 * A manual payment gets them from {@see PaymentInput::toFacts()}, after every key was validated; a system caller (a
 * matched Stripe payment) builds them from the provider's facts. The amount is in the currency of the document, the
 * dates are Prague days (`Y-m-d`), `incomeDate` is the day of the income transaction, `categoryId` is a selectable
 * income category or null for the system one.
 */
final readonly class PaymentFacts
{
    public function __construct(
        public Money $amount,
        public string $paidOn,
        public PaymentMethod $method,
        public string $incomeDate,
        public ?string $categoryId,
        public ?string $note,
    ) {}
}

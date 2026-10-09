<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Billing;

use App\Domain\Shared\Money\Money;

/**
 * The billing that really applies to a task, resolved at read time (D-14),
 * with the level that supplied each value.
 *
 * `type` is `hourly`, `fixed_price` or `non_billable`; `inherit` never appears
 * because the project always supplies a type. The other fields are null while
 * no level holds a value, and then their source is null too. The hourly rate
 * always has a source because the client holds a rate.
 *
 * Admin and system contexts only: it carries rates and prices (D-13).
 */
final readonly class EffectiveBilling
{
    public function __construct(
        public string $type,
        public BillingSource $typeSource,
        public ?Money $hourlyRate,
        public ?BillingSource $hourlyRateSource,
        public ?Money $fixedPrice,
        public ?BillingSource $fixedPriceSource,
        public ?int $estimateSeconds,
        public ?BillingSource $estimateSource,
    ) {}

    /**
     * False only for a non-billable task: whether the time of a task may be billed.
     */
    public function isBillable(): bool
    {
        return $this->type !== 'non_billable';
    }
}

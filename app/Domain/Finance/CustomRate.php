<?php

declare(strict_types=1);

namespace App\Domain\Finance;

/**
 * The own exchange rate of one transaction (D-06): a rate the caller gives for this single transaction.
 *
 * It is never a general rate and is never written to `exchange_rates`; it only becomes the snapshot of the
 * transaction it was given for. `$rateDate` null means the transaction date.
 */
final readonly class CustomRate
{
    public function __construct(
        public string $rate,
        public int $unitAmount = 1,
        public ?string $rateDate = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Billing;

use App\Domain\Shared\Money\Money;

/**
 * The effective hourly rate of a time entry with the level that supplied it.
 *
 * Both are null together when no level holds a usable rate; the screens then
 * show a dash. Nothing is stored on the entry in Phase 6: the amount snapshot
 * is written at billing time in Phase 10. Admin and system contexts only,
 * because it carries a rate.
 */
final readonly class EntryRate
{
    public function __construct(
        public ?Money $rate,
        public ?RateSource $source,
    ) {}
}

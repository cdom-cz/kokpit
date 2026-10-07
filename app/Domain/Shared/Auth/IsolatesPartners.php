<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

/**
 * Registers the fail-closed PartnerScope on a model that implements
 * PartnerIsolated.
 *
 * Until the DeniesPartners trait lands (task 3 of plan 02-10) only the
 * test-only CanaryRecord uses it, which PHPStan does not analyse.
 *
 * @phpstan-ignore trait.unused
 */
trait IsolatesPartners
{
    public static function bootIsolatesPartners(): void
    {
        static::addGlobalScope(new PartnerScope);
    }
}

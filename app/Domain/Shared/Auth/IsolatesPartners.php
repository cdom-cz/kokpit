<?php

declare(strict_types=1);

namespace App\Domain\Shared\Auth;

/**
 * Registers the fail-closed PartnerScope on a model that implements
 * PartnerIsolated.
 */
trait IsolatesPartners
{
    public static function bootIsolatesPartners(): void
    {
        static::addGlobalScope(new PartnerScope);
    }
}

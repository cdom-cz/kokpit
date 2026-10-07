<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Shared\Auth\AccessRules;

/**
 * Makes a Filament Resource's access the AND of its #[AccessRule] and its
 * policy (D-03).
 *
 * The declaration is checked first and fail-closed; the policy check of the
 * parent still runs, so strict authorization keeps throwing for a Resource
 * without a policy method and a policy can still narrow the declaration.
 *
 * @phpstan-ignore trait.unused (first real Resource arrives in Phase 4; the canary Resource in tests uses it today)
 */
trait EnforcesResourceAccessRule
{
    public static function canAccess(): bool
    {
        return AccessRules::allows(static::class) && parent::canAccess();
    }
}

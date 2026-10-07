<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Shared\Auth\AccessRules;

/**
 * Makes a Filament Widget's visibility follow its #[AccessRule] (D-03).
 *
 * Widgets default to visible for every signed-in user; this replaces the
 * default with the fail-closed declaration.
 *
 * @phpstan-ignore trait.unused (first real Widget arrives with the dashboard content; tests use it today)
 */
trait EnforcesWidgetAccessRule
{
    public static function canView(): bool
    {
        return AccessRules::allows(static::class);
    }
}

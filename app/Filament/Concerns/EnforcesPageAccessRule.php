<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Shared\Auth\AccessRules;

/**
 * Makes a Filament Page's access follow its #[AccessRule] (D-03).
 *
 * Filament pages default to open for every signed-in user; this replaces the
 * default with the fail-closed declaration. The method serves navigation, the
 * route and Livewire hydration alike.
 */
trait EnforcesPageAccessRule
{
    public static function canAccess(): bool
    {
        return AccessRules::allows(static::class);
    }
}

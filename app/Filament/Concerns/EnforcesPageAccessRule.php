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
 *
 * Livewire runs a component's own mount() before Filament's mount-time access
 * check, so a page with its own mount() would execute it for a Partner. The
 * boot hook runs earlier than any mount() and refuses first.
 *
 * canAccess() is overridable: a class method wins over a trait method, so a page
 * that declares its own canAccess() can widen what it admits. The boot hook asks
 * the declaration directly as well (like the relation manager and widget traits),
 * so such an override can only narrow the access, never widen it.
 */
trait EnforcesPageAccessRule
{
    public static function canAccess(): bool
    {
        return AccessRules::allows(static::class);
    }

    public function bootEnforcesPageAccessRule(): void
    {
        abort_unless(AccessRules::allows(static::class) && static::canAccess(), 403);
    }
}

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
 * Filament runs its own visibility check at boot, before mount(). That check
 * calls canView(), so a widget that overrides it can widen what it admits. The
 * boot hook below asks the declaration as well and cannot be widened: a user
 * the declaration refuses never reaches the widget's own code (research
 * Pitfall 1).
 */
trait EnforcesWidgetAccessRule
{
    public static function canView(): bool
    {
        return AccessRules::allows(static::class);
    }

    public function bootEnforcesWidgetAccessRule(): void
    {
        abort_unless(AccessRules::allows(static::class) && static::canView(), 403);
    }
}

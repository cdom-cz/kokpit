<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Shared\Auth\AccessRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a relation manager's visibility the AND of its #[AccessRule] and the
 * parent check of the related resource or policy (D-03).
 *
 * Filament runs its own visibility check at boot, before mount(). That check
 * calls canViewForRecord(), so a class that overrides it can widen what it
 * admits. The boot hook below asks the declaration directly and cannot be
 * widened: a user the declaration refuses never reaches the class's own code
 * (research Pitfall 1).
 */
trait EnforcesRelationManagerAccessRule
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return AccessRules::allows(static::class) && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function bootEnforcesRelationManagerAccessRule(): void
    {
        abort_unless(AccessRules::allows(static::class), 403);
    }
}

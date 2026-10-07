<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Shared\Auth\AccessRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a relation manager's visibility the AND of its #[AccessRule] and the
 * parent check of the related resource or policy (D-03).
 *
 * @phpstan-ignore trait.unused (first real relation manager arrives in Phase 4; tests use it today)
 */
trait EnforcesRelationManagerAccessRule
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return AccessRules::allows(static::class) && parent::canViewForRecord($ownerRecord, $pageClass);
    }
}

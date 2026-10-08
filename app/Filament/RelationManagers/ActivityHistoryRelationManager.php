<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * Placeholder: the read-only history relation manager arrives with the GREEN step.
 */
abstract class ActivityHistoryRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'activitiesAsSubject';
}

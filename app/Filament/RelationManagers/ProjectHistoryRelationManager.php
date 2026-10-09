<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The change history of one project: the allowlisted changes to its identity,
 * status, priority, dates and visibility (D-06, D-07). Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what; a Partner never sees it.')]
final class ProjectHistoryRelationManager extends ActivityHistoryRelationManager {}

<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The change history of one task: the allowlisted changes to its identity,
 * status, priority, dates, people and escalation (D-06, D-07). The description
 * and the board position are never logged. Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what; a Partner never sees it.')]
final class TaskHistoryRelationManager extends ActivityHistoryRelationManager {}

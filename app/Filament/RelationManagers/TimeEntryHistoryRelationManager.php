<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The change history of one time entry: the allowlisted changes to its context, its instants,
 * the billable flag and its billing state (D-06). The description is never logged. Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what and when time was billed; a Partner never sees measured time.')]
final class TimeEntryHistoryRelationManager extends ActivityHistoryRelationManager {}

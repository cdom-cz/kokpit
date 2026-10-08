<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The change history of one client: the allowlisted changes to its billing data
 * and terms (D-06). Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what; a Partner never sees it.')]
final class ClientHistoryRelationManager extends ActivityHistoryRelationManager {}

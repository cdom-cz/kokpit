<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The change history of one transaction: the allowlisted changes to its amounts, its CZK figure with the source
 * and rate, its category, client and project and its short text columns (D-14). The description is never logged.
 * Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed which finance figure and when; a Partner never sees finance.')]
final class TransactionHistoryRelationManager extends ActivityHistoryRelationManager {}

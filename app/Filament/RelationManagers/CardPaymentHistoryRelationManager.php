<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The change history of one card payment record: the allowlisted changes to its resolution, its amounts and its
 * document links (D-14). A Stripe reference and the resolution note are never logged, so they are never shown here.
 * Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who resolved which card payment and when; a Partner never sees finance.')]
final class CardPaymentHistoryRelationManager extends ActivityHistoryRelationManager {}

<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The change history of one billing document: the allowlisted changes to its state, number, dates and totals (D-13).
 * The printed and the internal note are never logged, and a cancellation writes nothing on the invoice itself (plan
 * 10-04 R-04-5), so it shows through the cancelled banner and the credit note link, not as a row here. Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed which figure of a billing document and when; a Partner never sees it.')]
final class InvoiceHistoryRelationManager extends ActivityHistoryRelationManager {}

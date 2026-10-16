<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\ManagesDocuments;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Soubory" tab of a transaction (FI-01, DO-01, D-12): its receipts. Admin only and never shared: a receipt is
 * finance data, so the visibility switch is not offered (the owner kind is not shareable, D-03).
 */
#[AccessRule(Audience::AdminOnly, reason: 'Receipts are finance data; only the Admin sees and manages them, a Partner has no transaction screen and no receipt access.')]
final class TransactionDocumentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use ManagesDocuments;

    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('finance.transactions.receipts.relation_title');
    }

    protected function showsOwnerColumn(): bool
    {
        return false;
    }
}

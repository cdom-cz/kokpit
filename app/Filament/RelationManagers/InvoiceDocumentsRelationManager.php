<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\ManagesDocuments;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Soubory" tab of an issued document (IN-04, DO-01, DO-03; D-21): the stored PDF with its preview and download,
 * and any other file the Admin adds. Admin only and never shared: a billing document is finance data, so the
 * visibility switch is not offered (the owner kind is not shareable, D-03). Deleting the PDF of the document asks for
 * the explicit confirmation of plan 10-36 on this tab as on every other screen.
 *
 * A draft has no PDF yet, so the tab is not offered on the editor.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The files of a billing document, its PDF first, are finance data; only the Admin sees and manages them.')]
final class InvoiceDocumentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule {
        canViewForRecord as private canViewForAdmin;
    }
    use ManagesDocuments;

    protected static string $relationship = 'documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::canViewForAdmin($ownerRecord, $pageClass)
            && $ownerRecord instanceof Invoice
            && $ownerRecord->isIssued();
    }

    protected function showsOwnerColumn(): bool
    {
        return false;
    }
}

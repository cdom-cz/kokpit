<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\ShowsPartnerDocuments;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * The read-only "Soubory" tab of the Partner task page (DO-01, D-04): the files hanging directly on the task
 * that the Admin flagged visible. The files of the task's comments appear in the comment thread, not here. The
 * behaviour is {@see ShowsPartnerDocuments}.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner reads and downloads the shared files of a task of an own client-visible project; the Document model scope decides which rows exist and the tab offers no write action.')]
final class PartnerTaskDocumentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use ShowsPartnerDocuments;

    protected static string $relationship = 'documents';
}

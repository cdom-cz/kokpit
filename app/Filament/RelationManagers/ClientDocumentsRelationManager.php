<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\ManagesDocuments;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * The "Soubory" tab of the client detail (DO-01, D-02, research A10): every file of the client, its own and those of its projects, tasks and comments, each with the owner it hangs on. A file uploaded here belongs to the client and is always hidden from the client. Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The files of a client include internal and hidden ones; a Partner reads only what is shared with them, on the project and task tabs.')]
final class ClientDocumentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use ManagesDocuments;

    protected static string $relationship = 'documents';

    protected function showsOwnerColumn(): bool
    {
        return true;
    }
}

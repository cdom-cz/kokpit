<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\ManagesDocuments;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * The "Soubory" tab of the project detail (DO-01, D-02): the files that hang on the project itself. Admin only; a Partner has its own read-only tab (plan 09-08).
 */
#[AccessRule(Audience::AdminOnly, reason: 'The Admin manages every project file; Partners have their own read-only tab with the files shared with them.')]
final class ProjectDocumentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use ManagesDocuments;

    protected static string $relationship = 'documents';

    protected function showsOwnerColumn(): bool
    {
        return false;
    }
}

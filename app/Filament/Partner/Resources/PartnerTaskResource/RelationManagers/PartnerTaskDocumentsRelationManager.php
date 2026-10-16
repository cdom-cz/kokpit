<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers;

use App\Domain\Documents\Models\Document;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\ShowsPartnerDocuments;
use App\Filament\Support\DocumentActions;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Gate;

/**
 * The "Soubory" tab of the Partner task page (DO-01, D-04): the files hanging directly on the task that are
 * flagged visible. The files of the task's comments appear in the comment thread, not here. The behaviour is
 * {@see ShowsPartnerDocuments}.
 *
 * The one write action is the delete of an own upload while the task is open (D-05, DO-03). It is shown only
 * where the policy grants it and asks for a confirmation that names the file; the policy is checked again
 * when it runs, so a forged call is refused as well.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner reads and downloads the shared files of a task of an own client-visible project; the Document model scope decides which rows exist and the one write action, deleting an own file of an open task, is granted by DocumentPolicy.')]
final class PartnerTaskDocumentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use ShowsPartnerDocuments;

    protected static string $relationship = 'documents';

    /**
     * @return list<Action>
     */
    protected function extraRecordActions(): array
    {
        return [
            DocumentActions::delete()
                ->visible(static fn (Document $record): bool => Gate::allows('delete', $record)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Support\DocumentActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;

/**
 * The Admin's central "Soubory" listing (D-07): the table of every file, and the one header action that
 * the tabs of a client, project, task or transaction cannot offer, the upload of a file that hangs on nothing.
 */
final class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    public function getTitle(): string
    {
        return __('documents.navigation');
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            DocumentActions::uploadUnattached(),
        ];
    }
}

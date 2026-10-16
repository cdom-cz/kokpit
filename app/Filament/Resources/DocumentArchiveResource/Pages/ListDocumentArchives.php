<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentArchiveResource\Pages;

use App\Domain\Documents\Models\DocumentArchive;
use App\Filament\Resources\DocumentArchiveResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The list of the signed-in Admin's ZIP archives (DO-02, D-08): a heading, the retention line and the table. No
 * header action: archives are requested from the central files listing.
 */
final class ListDocumentArchives extends ListRecords
{
    protected static string $resource = DocumentArchiveResource::class;

    public function getTitle(): string
    {
        return __('documents.archives.navigation');
    }

    public function getSubheading(): string
    {
        return __('documents.archives.subheading', ['days' => DocumentArchive::ttlDays()]);
    }
}

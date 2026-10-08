<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerProjectResource\Pages;

use App\Filament\Partner\Resources\PartnerProjectResource;
use Filament\Resources\Pages\ListRecords;

final class ListPartnerProjects extends ListRecords
{
    protected static string $resource = PartnerProjectResource::class;

    public function getTitle(): string
    {
        return __('kokpit.partner_projects.plural_model_label');
    }

    /**
     * The list is read-only: no create action.
     *
     * @return array<never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

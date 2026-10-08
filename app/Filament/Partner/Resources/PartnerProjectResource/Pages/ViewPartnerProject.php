<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerProjectResource\Pages;

use App\Filament\Partner\Resources\PartnerProjectResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewPartnerProject extends ViewRecord
{
    protected static string $resource = PartnerProjectResource::class;

    /**
     * The detail is read-only: no edit or delete action.
     *
     * @return array<never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

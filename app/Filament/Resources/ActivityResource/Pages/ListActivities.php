<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityResource\Pages;

use App\Filament\Resources\ActivityResource;
use Filament\Resources\Pages\ListRecords;

final class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    public function getTitle(): string
    {
        return __('kokpit.activity.title');
    }

    /**
     * The audit trail is read-only: no create action.
     *
     * @return array<never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

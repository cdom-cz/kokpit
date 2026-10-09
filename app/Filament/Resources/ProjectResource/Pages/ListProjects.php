<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    public function getTitle(): string
    {
        return __('kokpit.projects.plural_model_label');
    }

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

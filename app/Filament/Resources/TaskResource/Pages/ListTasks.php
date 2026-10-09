<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    public function getTitle(): string
    {
        return __('kokpit.tasks.plural_model_label');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            TaskResource::quickCreateAction(),
        ];
    }
}

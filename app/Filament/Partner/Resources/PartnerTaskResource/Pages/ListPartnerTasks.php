<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\Pages;

use App\Filament\Partner\Resources\PartnerTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPartnerTasks extends ListRecords
{
    protected static string $resource = PartnerTaskResource::class;

    public function getTitle(): string
    {
        return __('kokpit.partner_tasks.plural_model_label');
    }

    /**
     * The list is read-only; the one action is raising a new task.
     *
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('kokpit.partner_tasks.actions.create')),
        ];
    }
}

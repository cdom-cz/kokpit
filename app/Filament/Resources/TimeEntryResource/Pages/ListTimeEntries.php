<?php

declare(strict_types=1);

namespace App\Filament\Resources\TimeEntryResource\Pages;

use App\Filament\Resources\TimeEntryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * The list of every time entry, with "Nový záznam" as the one accent action.
 */
final class ListTimeEntries extends ListRecords
{
    protected static string $resource = TimeEntryResource::class;

    public function getTitle(): string
    {
        return __('kokpit.time.plural_model_label');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('kokpit.time.new_entry'))
                ->icon(Heroicon::OutlinedPlus),
        ];
    }
}

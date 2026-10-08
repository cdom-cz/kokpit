<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Domain\Projects\Models\Project;
use App\Filament\Resources\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')
                ->label(__('kokpit.task_board.project_action'))
                ->icon(Heroicon::OutlinedViewColumns)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('board', ['record' => $this->getRecord()])),
            EditAction::make(),
            ProjectResource::archiveAction(DeleteAction::make()),
            ProjectResource::restoreAction(RestoreAction::make()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $project = $this->getRecord();

        return $project instanceof Project ? ProjectResource::fillBillingState($project, $data) : $data;
    }
}

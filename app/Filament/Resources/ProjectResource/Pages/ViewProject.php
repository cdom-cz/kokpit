<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Domain\Projects\Models\Project;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Widgets\ProjectTimeStats;
use App\Filament\Resources\TaskResource;
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
            $this->newTaskAction(),
            EditAction::make(),
            ProjectResource::archiveAction(DeleteAction::make()),
            ProjectResource::restoreAction(RestoreAction::make()),
        ];
    }

    /**
     * "Nový úkol" (D-07): the quick create modal of the task list with this project locked.
     * Hidden, not disabled, for a Done project, an archived project and a project of an
     * archived client; the project is read from the page record, never from the modal.
     */
    private function newTaskAction(): Action
    {
        $project = $this->getRecord();
        assert($project instanceof Project);

        return TaskResource::quickCreateAction($project, lockProject: true)
            ->color('gray')
            ->visible(fn (): bool => TaskResource::canAccess()
                && Project::query()->selectable()->open()->whereKey($project->getKey())->exists());
    }

    /**
     * The time row of the project (PR-05); the widget refuses everybody but the Admin.
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [ProjectTimeStats::class];
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

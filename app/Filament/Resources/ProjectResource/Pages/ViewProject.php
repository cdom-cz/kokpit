<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Widgets\ProjectTimeStats;
use App\Filament\Resources\TaskResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

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
            $this->changeStatusAction(),
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
     * "Změnit stav" (D-12): the status is changed from the project page with the same effect as
     * saving the edit form. The write is {@see UpdateProject} with the status only (every other
     * column, the tags and the billing row keep their values, one history row is written). The
     * action is shown only while {@see ProjectResource::canEdit()} holds and the project is not
     * archived; the body checks it again, because /livewire/update is public and UpdateProject has
     * no Gate of its own.
     */
    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('kokpit.tasks.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalHeading(__('kokpit.projects.actions.change_status_heading'))
            ->modalSubmitActionLabel(__('kokpit.tasks.actions.change_status_submit'))
            ->successNotificationTitle(__('kokpit.projects.notifications.status_changed'))
            ->visible(function (): bool {
                $project = $this->getRecord();

                return $project instanceof Project && ProjectResource::canEdit($project) && ! $project->trashed();
            })
            ->fillForm(function (): array {
                $project = $this->getRecord();
                assert($project instanceof Project);

                return ['status' => ProjectResource::enumValue($project->status)];
            })
            ->schema([
                Select::make('status')
                    ->label(__('kokpit.projects.fields.status'))
                    ->options(ProjectStatus::class)
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data, Action $action): void {
                $project = $this->getRecord();
                assert($project instanceof Project);

                abort_unless(ProjectResource::canAccess() && ProjectResource::canEdit($project) && ! $project->trashed(), 403);

                $status = ProjectStatus::tryFrom((string) ProjectResource::enumValue($data['status'] ?? null));

                if (! $status instanceof ProjectStatus) {
                    throw ValidationException::withMessages(['mountedActions.0.data.status' => __('kokpit.projects.errors.status_invalid')]);
                }

                try {
                    app(UpdateProject::class)->handle($project, ['status' => $status->value]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages([
                        'mountedActions.0.data.status' => $e->errors()['status'] ?? [(string) collect($e->errors())->flatten()->first()],
                    ]);
                }

                // The disabled form of the view page shows the new status in the same response.
                $project->refresh();
                $this->refreshFormData(['status']);

                $action->success();
            });
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

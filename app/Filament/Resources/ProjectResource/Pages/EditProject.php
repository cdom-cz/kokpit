<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Models\Project;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edits a project and its billing row through the domain Action. The client is
 * shown read-only and is not part of the saved state.
 */
final class EditProject extends EditRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = ProjectResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Project);

        // A frozen key field is disabled and so not part of the form data: the stored key is kept.
        $data['key'] ??= $record->key;

        return $this->withFormErrors(
            static fn (): Project => app(UpdateProject::class)->handle($record, ProjectResource::actionData($data)),
        );
    }
}

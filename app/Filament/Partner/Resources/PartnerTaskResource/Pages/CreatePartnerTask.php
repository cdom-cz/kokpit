<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\Pages;

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Partner\Resources\PartnerTaskResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * A Partner raises a task: project, title and description go to the domain
 * Action CreateTask with the Partner as actor. The Action decides the status,
 * the priority and the people (D-04), so nothing else of the form state is read
 * but the files (D-05): only the temporary uploads Livewire holds are handed on to
 * AttachDocuments, in the same database transaction as the task, so the task and
 * its files are created together or not at all.
 */
final class CreatePartnerTask extends CreateRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = PartnerTaskResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('kokpit.partner_tasks.actions.create_heading');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormErrors(function () use ($data): Model {
            $actor = auth()->user();
            abort_unless($actor instanceof User, 403);

            // The scoped lookup: an unknown, foreign or hidden project is the same neutral field error.
            $projectId = is_string($data['project_id'] ?? null) ? $data['project_id'] : '';
            $project = $projectId === '' ? null : Project::query()->selectable()->find($projectId);

            if (! $project instanceof Project) {
                throw ValidationException::withMessages(['project_id' => __('kokpit.tasks.errors.project_unavailable')]);
            }

            $files = array_values(array_filter(
                is_array($data['files'] ?? null) ? $data['files'] : [],
                static fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
            ));

            return DB::transaction(function () use ($actor, $project, $data, $files): Model {
                $task = app(CreateTask::class)->handle($actor, $project, [
                    'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
                    'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
                ]);

                if ($files !== []) {
                    try {
                        app(AttachDocuments::class)->handle($actor, $task, $files);
                    } catch (AuthorizationException) {
                        throw ValidationException::withMessages(['files' => __('documents.errors.partner_not_allowed')]);
                    }
                }

                return $task;
            });
        });
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('kokpit.partner_tasks.notifications.created');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('kokpit.partner_tasks.actions.create_submit'));
    }
}

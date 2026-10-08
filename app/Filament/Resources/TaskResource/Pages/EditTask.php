<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\TaskResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edits a task through the domain Action UpdateTask (TA-01). The page never
 * writes a row itself: the save hands the mapped form state to the Action,
 * which owns the people check, the locked status change and the sanitising.
 */
final class EditTask extends EditRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = TaskResource::class;

    public function getTitle(): string
    {
        $task = $this->getRecord();

        return $task instanceof Task ? $task->reference.' · '.$task->title : __('kokpit.tasks.model_label');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $task = $this->getRecord();

        return $task instanceof Task ? TaskResource::fillData($task, $data) : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Task);

        $actor = auth()->user();
        assert($actor instanceof User);

        return $this->withFormErrors(
            static fn (): Task => app(UpdateTask::class)->handle($actor, $record, TaskResource::actionData($data)),
        );
    }
}

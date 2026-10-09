<?php

declare(strict_types=1);

namespace App\Filament\Resources\TimeEntryResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\CreateTimeEntry as CreateTimeEntryAction;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\TimeEntryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a finished entry by hand through the domain Action CreateTimeEntry; a
 * domain error lands under its field.
 */
final class CreateTimeEntry extends CreateRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = TimeEntryResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('kokpit.time.create_heading');
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('kokpit.time.saved');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('kokpit.time.save'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $this->withFormErrors(
            static fn (): Model => app(CreateTimeEntryAction::class)->handle($actor, TimeEntryResource::actionData($data)),
        );
    }
}

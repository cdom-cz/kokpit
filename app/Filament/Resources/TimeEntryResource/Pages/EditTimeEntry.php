<?php

declare(strict_types=1);

namespace App\Filament\Resources\TimeEntryResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\TimeEntryResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Corrects an entry through the domain Action UpdateTimeEntry. The page never writes a
 * row itself: the Action owns the context rules, the times and the billed lock.
 *
 * A running entry keeps its Konec empty: the form shows the "Běží" badge in its place.
 */
final class EditTimeEntry extends EditRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = TimeEntryResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up: the deny-all
     * scope of measured time would answer 404 first.
     */
    public function mount(int|string $record): void
    {
        abort_unless(TimeEntryResource::canAccess(), 403);

        // A billed entry has no edit page (D-06): its URL shows the view page. This runs before
        // `parent::mount()`, which would authorize the edit and answer 403 first (Pitfall 4).
        $entry = $this->resolveRecord($record);

        if ($entry instanceof TimeEntry && $entry->isBilled()) {
            $this->record = $entry;
            $this->redirect(TimeEntryResource::getUrl('view', ['record' => $entry]));

            return;
        }

        parent::mount($record);
    }

    public function getTitle(): string
    {
        return __('kokpit.time.edit_entry');
    }

    public function form(Schema $schema): Schema
    {
        $entry = $this->getRecord();

        return $schema->components(TimeEntryResource::entryFields($entry instanceof TimeEntry && $entry->isRunning()));
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('kokpit.time.saved');
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
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof TimeEntry);

        $actor = auth()->user();
        assert($actor instanceof User);

        return $this->withFormErrors(function () use ($actor, $record, $data): TimeEntry {
            try {
                return app(UpdateTimeEntry::class)->handle($actor, $record, TimeEntryResource::actionData($data));
            } catch (DomainException $e) {
                // A billed entry is refused (D-06): the form stays as it is.
                Notification::make()->danger()->title($e->getMessage())->send();

                throw new Halt;
            }
        });
    }
}

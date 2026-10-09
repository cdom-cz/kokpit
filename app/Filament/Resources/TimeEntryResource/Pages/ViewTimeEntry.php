<?php

declare(strict_types=1);

namespace App\Filament\Resources\TimeEntryResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\CancelEntriesBilling;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource;
use App\Providers\LocalisationServiceProvider;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;

/**
 * The page of one entry. The heading is the date and the time range.
 */
final class ViewTimeEntry extends ViewRecord
{
    protected static string $resource = TimeEntryResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up: the deny-all
     * scope of measured time would answer 404 first.
     */
    public function mount(int|string $record): void
    {
        abort_unless(TimeEntryResource::canAccess(), 403);

        parent::mount($record);
    }

    public function getTitle(): string
    {
        $entry = $this->getRecord();

        if (! $entry instanceof TimeEntry) {
            return __('kokpit.time.model_label');
        }

        return $entry->started_at->setTimezone(FilamentTimezone::get())->format(LocalisationServiceProvider::DATE_FORMAT)
            .' '.TimeEntryResource::timeRangeText($entry);
    }

    /**
     * An unbilled entry offers "Upravit záznam" and, when it is finished, "Smazat záznam"; a
     * billed one offers only "Zrušit fakturaci", the one way to unlock it (D-06).
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('kokpit.time.edit_entry'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (): bool => $this->entry() instanceof TimeEntry && TimeEntryResource::canEdit($this->entry())),
            TimeEntryResource::deleteAction(DeleteAction::make())
                ->successRedirectUrl(TimeEntryResource::getUrl('index')),
            Action::make('cancelBilling')
                ->label(__('kokpit.time.billing.cancel.action'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('kokpit.time.billing.cancel.heading'))
                ->modalDescription(trans_choice('kokpit.time.billing.cancel.body', 1, ['count' => 1]))
                ->modalSubmitActionLabel(__('kokpit.time.billing.cancel.action'))
                ->visible(fn (): bool => $this->entry()?->isBilled() === true)
                ->action(fn () => $this->cancelBilling()),
        ];
    }

    /**
     * Unlocks this one entry through the same Action as the bulk cancel and updates the page's
     * record, so the callout, the badge and the header actions follow the new state.
     */
    private function cancelBilling(): void
    {
        $entry = $this->entry();
        $actor = auth()->user();
        assert($entry instanceof TimeEntry && $actor instanceof User);

        try {
            $count = app(CancelEntriesBilling::class)->handle($actor, [$entry->getKey()]);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        // The schemas of the page hold this very instance, so the record is changed in place.
        $entry->setRawAttributes([...$entry->getAttributes(), 'billing_state' => BillingState::Unbilled->value, 'billed_at' => null], sync: true);
        $this->dispatch('time-entry-saved');

        Notification::make()->success()->title(__('kokpit.time.billing.cancel.done', ['count' => $count]))->send();
    }

    private function entry(): ?TimeEntry
    {
        $record = $this->getRecord();

        return $record instanceof TimeEntry ? $record : null;
    }
}

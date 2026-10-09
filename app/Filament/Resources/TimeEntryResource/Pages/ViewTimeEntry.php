<?php

declare(strict_types=1);

namespace App\Filament\Resources\TimeEntryResource\Pages;

use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource;
use App\Providers\LocalisationServiceProvider;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Facades\FilamentTimezone;

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
}
